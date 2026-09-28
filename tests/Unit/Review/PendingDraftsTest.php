<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use MerchantQuoteAgentPlugin\Review\DecisionNotFound;
use MerchantQuoteAgentPlugin\Review\DraftNotReviewable;
use MerchantQuoteAgentPlugin\Review\PendingDraft;
use MerchantQuoteAgentPlugin\Review\PendingDrafts;
use MerchantQuoteAgentPlugin\Review\ReviewFingerprint;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingLock;
use MerchantQuoteAgentPlugin\Servicing\ServicingFingerprint;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\QuoteSnapshotFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class PendingDraftsTest extends TestCase
{
    private const VERSION = '0190aaaa0000700080000000000000aa';

    public function testAFreshDraftIsHandedOverNotStaleWithItsDraftGateway(): void
    {
        $live = QuoteSnapshotFixture::snapshot();
        $store = new FakeReviewStore();
        $store->record = self::record(
            'pending',
            '0190aaaa0000700080000000000000aa',
            ReviewFingerprint::atDraft($live, $live),
        );
        $versions = new FakeDraftVersions(new FakeQuoteGateway([$live]));

        $seen = self::drafts($store, $versions, $live)->with('rec', static fn(PendingDraft $d): PendingDraft => $d);

        self::assertFalse($seen->stale);
        self::assertSame($versions->draft, $seen->draft);
    }

    public function testADraftWhoseQuoteMovedOnIsStale(): void
    {
        $live = QuoteSnapshotFixture::snapshot(comments: [QuoteSnapshotFixture::buyerComment(
            '2026-09-23 12:00:00.000',
        )]);
        $store = new FakeReviewStore();
        $atDraft = QuoteSnapshotFixture::snapshot();
        $store->record = self::record('pending', null, ReviewFingerprint::atDraft($atDraft, $atDraft));

        $seen = self::drafts($store, new FakeDraftVersions(new FakeQuoteGateway([$live])), $live)
            ->with('rec', static fn(PendingDraft $d): PendingDraft => $d);

        self::assertTrue($seen->stale);
        self::assertNull($seen->draft);
    }

    public function testASentDraftIsNotReviewable(): void
    {
        $store = new FakeReviewStore();
        $store->record = self::record('sent', null, 'x');

        $this->expectException(DraftNotReviewable::class);

        self::drafts(
            $store,
            new FakeDraftVersions(new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()])),
            QuoteSnapshotFixture::snapshot(),
        )
            ->with('rec', static fn(PendingDraft $d): bool => true);
    }

    public function testAnUnknownDecisionIsNotFound(): void
    {
        $this->expectException(DecisionNotFound::class);

        self::drafts(
            new FakeReviewStore(),
            new FakeDraftVersions(new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()])),
            QuoteSnapshotFixture::snapshot(),
        )
            ->with('rec', static fn(PendingDraft $d): bool => true);
    }

    public function testAQuoteThatIsBeingServicedIsBusy(): void
    {
        $live = QuoteSnapshotFixture::snapshot();
        $store = new FakeReviewStore();
        $store->record = self::record('pending', null, ServicingFingerprint::of($live));
        $factory = new LockFactory(new InMemoryStore());
        $held = (new QuoteServicingLock($factory, 'flock'))->for('q1');
        self::assertTrue($held->acquire());

        $this->expectException(DraftNotReviewable::class);

        (new PendingDrafts(
            $store,
            new FakeDraftVersions(new FakeQuoteGateway([$live])),
            new FakeQuoteGateway([$live]),
            new QuoteServicingLock($factory, 'flock'),
        ))->with('rec', static fn(PendingDraft $d): bool => true);
    }

    /**
     * A versioned read of a version with no rows left falls back to the live
     * quote, so handing its gateway over would review — and send — the live
     * prices as the draft.
     */
    public function testADraftWhoseVersionIsGoneIsNotReviewableAndTheLockIsReleased(): void
    {
        $live = QuoteSnapshotFixture::snapshot();
        $store = new FakeReviewStore();
        $store->record = self::record('pending', self::VERSION, ServicingFingerprint::of($live));
        $versions = new FakeDraftVersions(new FakeQuoteGateway([$live]));
        $versions->missing = [self::VERSION];
        $factory = new LockFactory(new InMemoryStore());

        try {
            (new PendingDrafts(
                $store,
                $versions,
                new FakeQuoteGateway([$live]),
                new QuoteServicingLock($factory, 'flock'),
            ))->with('rec', static fn(PendingDraft $d): bool => true);
            self::fail('A draft whose version is gone was handed over.');
        } catch (DraftNotReviewable $e) {
            self::assertSame('gone', $e->reason);
        }

        self::assertTrue((new QuoteServicingLock($factory, 'flock'))->for('q1')->acquire(), 'The quote lock leaked.');
    }

    /** Reject must still be able to retire a row whose version is gone. */
    public function testWithAnyDraftHandsOverAGoneDraftWithoutAGateway(): void
    {
        $live = QuoteSnapshotFixture::snapshot();
        $store = new FakeReviewStore();
        $store->record = self::record('pending', self::VERSION, ServicingFingerprint::of($live));
        $versions = new FakeDraftVersions(new FakeQuoteGateway([$live]));
        $versions->missing = [self::VERSION];

        $seen = self::drafts($store, $versions, $live)
            ->withAnyDraft('rec', static fn(PendingDraft $d): PendingDraft => $d);

        self::assertNull($seen->draft);
        self::assertSame(self::VERSION, $seen->record->draftVersionId);

        $withReply = QuoteSnapshotFixture::snapshot(comments: [QuoteSnapshotFixture::merchantComment(
            '2026-09-23 12:00:00.000',
        )]);
        $store->record->sentChanges = ['publishingMerchantCommentCount' => 0];

        try {
            self::drafts($store, $versions, $withReply)->withAnyDraft('rec', static fn(PendingDraft $d): bool => true);
            self::fail('A buyer-visible send was made rejectable.');
        } catch (DraftNotReviewable $caught) {
            self::assertSame('published', $caught->reason);
        }
    }

    public function testWithAnyDraftHandsOverADraftThatStillExists(): void
    {
        $live = QuoteSnapshotFixture::snapshot();
        $store = new FakeReviewStore();
        $store->record = self::record('pending', self::VERSION, ServicingFingerprint::of($live));
        $versions = new FakeDraftVersions(new FakeQuoteGateway([$live]));

        $seen = self::drafts($store, $versions, $live)
            ->withAnyDraft('rec', static fn(PendingDraft $d): PendingDraft => $d);

        self::assertSame($versions->draft, $seen->draft);
    }

    private static function drafts(
        FakeReviewStore $store,
        FakeDraftVersions $versions,
        \MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot $live,
    ): PendingDrafts {
        return new PendingDrafts(
            $store,
            $versions,
            new FakeQuoteGateway([$live]),
            new QuoteServicingLock(new LockFactory(new InMemoryStore()), 'flock'),
        );
    }

    private static function record(string $status, ?string $versionId, string $fingerprint): QuoteDecisionRecord
    {
        $record = new QuoteDecisionRecord();
        $record->id = '0190bbbb0000700080000000000000bb';
        $record->quoteId = 'q1';
        $record->reviewStatus = $status;
        $record->draftVersionId = $versionId;
        $record->reviewFingerprint = $fingerprint;

        return $record;
    }
}
