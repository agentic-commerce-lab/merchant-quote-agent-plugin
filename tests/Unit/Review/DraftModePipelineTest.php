<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
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
use PHPUnit\Framework\TestCase;

final class DraftModePipelineTest extends TestCase
{
    public function testOutsideDraftModeTheInnerPipelineGetsTheLiveGatewayButPendingDraftsAreStillSuperseded(): void
    {
        $h = self::harness(NegotiationOutcome::Offered);
        $h->reviews->pendingVersions = ['0190aaaa0000700080000000000000aa'];

        $h->pipeline->service($h->snapshot, $h->live, self::settings(draftMode: false), self::context());

        self::assertSame($h->live, $h->inner->gateway);
        self::assertSame(['0190aaaa0000700080000000000000aa'], $h->versions->deleted);
        self::assertSame([], $h->notifier->notices);
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
