<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use MerchantQuoteAgentPlugin\Negotiation\ClarificationMarker;
use MerchantQuoteAgentPlugin\Review\DraftRejecter;
use MerchantQuoteAgentPlugin\Review\PendingDraft;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\QuoteSnapshotFixture;
use PHPUnit\Framework\TestCase;

final class DraftRejecterTest extends TestCase
{
    /**
     * The drafting pass set the marker live, but the buyer never saw the
     * question. Left set, their next ambiguous ask would escalate as already
     * asked.
     */
    public function testRejectingAClarificationReleasesItsMarker(): void
    {
        $live = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot(customFields: ClarificationMarker::set())]);
        $store = new FakeReviewStore();

        self::rejecter($live, $store)->reject(self::pending('clarified', null));

        self::assertSame([[ClarificationMarker::MARKER_KEY => null]], $live->customFieldWrites);
        self::assertSame(['rec-1'], $store->rejected);
    }

    public function testRejectingAnOfferDeletesItsVersionAndLeavesTheLiveQuoteAlone(): void
    {
        $live = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);
        $versions = new FakeDraftVersions(new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]));
        $store = new FakeReviewStore();

        (new DraftRejecter($versions, $live, $store))->reject(self::pending(
            'offered',
            '0190aaaa0000700080000000000000aa',
        ));

        self::assertSame([], $live->quoteUpdates);
        self::assertSame(['0190aaaa0000700080000000000000aa'], $versions->deleted);
        self::assertSame(['rec-1'], $store->rejected);
    }

    private static function rejecter(FakeQuoteGateway $live, FakeReviewStore $store): DraftRejecter
    {
        return new DraftRejecter(
            new FakeDraftVersions(new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()])),
            $live,
            $store,
        );
    }

    private static function pending(string $outcome, ?string $versionId): PendingDraft
    {
        $record = new QuoteDecisionRecord();
        $record->id = 'rec-1';
        $record->quoteId = 'q1';
        $record->outcome = $outcome;
        $record->draftVersionId = $versionId;

        return new PendingDraft($record, QuoteSnapshotFixture::snapshot(), null, false);
    }
}
