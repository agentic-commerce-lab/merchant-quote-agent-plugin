<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Negotiation\ClarificationMarker;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use MerchantQuoteAgentPlugin\Review\DraftingQuoteGateway;
use MerchantQuoteAgentPlugin\Review\DraftModePipeline;
use MerchantQuoteAgentPlugin\Servicing\Data\PassContext;
use MerchantQuoteAgentPlugin\Servicing\Data\ServicingTriggerReason;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeDecisionWriter;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\QuoteSnapshotFixture;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\ServicingSettingsFixture;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class DraftModePipelineTest extends TestCase
{
    public function testOutsideDraftModeTheInnerPipelineGetsTheLiveGatewayButPendingDraftsAreStillSuperseded(): void
    {
        $h = self::harness(NegotiationOutcome::Offered);
        $h->reviews->pending = [[
            'id' => 'rec-1',
            'versionId' => '0190aaaa0000700080000000000000aa',
            'clarified' => false,
        ]];

        $h->pipeline->service($h->snapshot, $h->live, self::settings(draftMode: false), self::context());

        self::assertSame($h->live, $h->inner->gateway);
        self::assertSame(['0190aaaa0000700080000000000000aa'], $h->versions->deleted);
        self::assertSame([], $h->reviews->pending);
        self::assertSame([], $h->notifier->notices);
        self::assertSame([], $h->live->customFieldWrites, 'Only a superseded clarification holds the marker.');
        self::assertSame($h->snapshot, $h->inner->snapshot);
    }

    /**
     * A drafted clarification set the marker live although the buyer never
     * saw the question. Superseding it must release the marker, and the next
     * pass must decide on a snapshot that no longer carries it — the one the
     * handler read still does, and ClarificationRound would escalate the
     * still-ambiguous ask as already asked.
     */
    #[TestWith([true], 'in Draft Mode')]
    #[TestWith([false], 'out of Draft Mode')]
    public function testSupersedingAClarificationReleasesItsMarkerBeforeTheNextPassDecides(bool $draftMode): void
    {
        $h = self::harness(NegotiationOutcome::Clarified);
        $marked = QuoteSnapshotFixture::snapshot(customFields: ClarificationMarker::set());
        $released = QuoteSnapshotFixture::snapshot();
        $h->live->replaceSnapshots([$released]);
        $h->reviews->pending = [['id' => 'rec-1', 'versionId' => null, 'clarified' => true]];

        $h->pipeline->service($marked, $h->live, self::settings(draftMode: $draftMode), self::context());

        self::assertSame([[ClarificationMarker::MARKER_KEY => null]], $h->live->customFieldWrites);
        self::assertSame(['updateQuote', 'fetchSnapshot'], \array_slice($h->live->calls, offset: 0, length: 2));
        self::assertSame($released, $h->inner->snapshot);
        self::assertSame([], $h->versions->deleted, 'A clarification has no version to delete.');
    }

    /**
     * The marker write can fail once the drafts were read, and Messenger then
     * redelivers the pass. The retry has to find the clarification still
     * pending: marked superseded first, it would find nothing to undo and the
     * marker would stay set for good.
     */
    public function testAFailedMarkerReleaseLeavesTheClarificationPendingForTheRetry(): void
    {
        $h = self::harness(NegotiationOutcome::Clarified);
        $marked = QuoteSnapshotFixture::snapshot(customFields: ClarificationMarker::set());
        $released = QuoteSnapshotFixture::snapshot();
        $h->live->replaceSnapshots([$released]);
        $h->reviews->pending = [['id' => 'rec-1', 'versionId' => null, 'clarified' => true]];
        $h->live->updateThrows = new \RuntimeException('quote write failed');

        try {
            $h->pipeline->service($marked, $h->live, self::settings(draftMode: true), self::context());
            self::fail('The failed marker write must reach Messenger.');
        } catch (\RuntimeException $e) {
            self::assertSame('quote write failed', $e->getMessage());
        }

        self::assertCount(1, $h->reviews->pending, 'The failed pass must leave the clarification pending.');

        $h->live->updateThrows = null;
        $h->pipeline->service($marked, $h->live, self::settings(draftMode: true), self::context());

        self::assertSame([[ClarificationMarker::MARKER_KEY => null]], $h->live->customFieldWrites);
        self::assertSame($released, $h->inner->snapshot);
        self::assertSame([], $h->reviews->pending, 'The retry supersedes it once the marker is released.');
    }

    public function testADraftedOfferNotifiesTheMerchantAndKeepsItsVersion(): void
    {
        $h = self::harness(NegotiationOutcome::Offered, writesAPrice: true);

        $outcome = $h->pipeline->service($h->snapshot, $h->live, self::settings(draftMode: true), self::context());

        self::assertSame(NegotiationOutcome::Offered, $outcome);
        self::assertInstanceOf(DraftingQuoteGateway::class, $h->inner->gateway);
        self::assertSame([], $h->versions->deleted);
        self::assertSame(QuoteEscalationReason::DraftReady, $h->notifier->notices[0]->reason ?? null);
    }

    /**
     * An acknowledgement posts a comment and moves the quote back to replied,
     * so in Draft Mode it is a draft like a clarification: the merchant is
     * told, and there is no version because it changes no price.
     */
    public function testADraftedAcknowledgementNotifiesTheMerchantAndKeepsNoVersion(): void
    {
        $h = self::harness(NegotiationOutcome::Acknowledged);

        $outcome = $h->pipeline->service($h->snapshot, $h->live, self::settings(draftMode: true), self::context());

        self::assertSame(NegotiationOutcome::Acknowledged, $outcome);
        self::assertSame([], $h->versions->created);
        self::assertSame(QuoteEscalationReason::DraftReady, $h->notifier->notices[0]->reason ?? null);
    }

    public function testAnEscalatedDraftPassDeletesItsVersionAndSendsNoDraftNotice(): void
    {
        $h = self::harness(NegotiationOutcome::Escalated, writesAPrice: true);

        $h->pipeline->service($h->snapshot, $h->live, self::settings(draftMode: true), self::context());

        self::assertSame($h->versions->created, $h->versions->deleted);
        self::assertSame([], $h->notifier->notices);
    }

    public function testAFailingPassDeletesItsVersionAndRethrows(): void
    {
        $h = self::harness(null, writesAPrice: true);

        try {
            $h->pipeline->service($h->snapshot, $h->live, self::settings(draftMode: true), self::context());
            self::fail('The inner failure must reach the caller.');
        } catch (\RuntimeException $e) {
            self::assertSame('inner failed', $e->getMessage());
        }

        self::assertSame($h->versions->created, $h->versions->deleted);
    }

    private static function settings(bool $draftMode): QuoteAgentSettings
    {
        $base = ServicingSettingsFixture::settings();

        return new QuoteAgentSettings($base->policy, $base->llm, null, draftMode: $draftMode);
    }

    private static function context(): PassContext
    {
        return new PassContext(ServicingTriggerReason::cases()[0], 0);
    }

    private static function harness(?NegotiationOutcome $returns, bool $writesAPrice = false): DraftModeHarness
    {
        $snapshot = QuoteSnapshotFixture::snapshot();
        $inner = new RecordingInnerPipeline($returns, $writesAPrice);
        $versions = new FakeDraftVersions(new FakeQuoteGateway([$snapshot]));
        $reviews = new FakeReviewStore();
        $notifier = new RecordingNotifier();

        return new DraftModeHarness(
            new DraftModePipeline(
                $inner,
                $versions,
                new DecisionRecorder(new FakeDecisionWriter()),
                $reviews,
                $notifier,
            ),
            $inner,
            new FakeQuoteGateway([$snapshot]),
            $versions,
            $reviews,
            $notifier,
            $snapshot,
        );
    }
}
