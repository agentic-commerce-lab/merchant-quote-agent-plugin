<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use MerchantQuoteAgentPlugin\Bridge\ContextBoundGateways;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Review\DraftEdits;
use MerchantQuoteAgentPlugin\Review\DraftNotReviewable;
use MerchantQuoteAgentPlugin\Review\DraftSendCompletion;
use MerchantQuoteAgentPlugin\Review\DraftSender;
use MerchantQuoteAgentPlugin\Review\InvalidReviewRequest;
use MerchantQuoteAgentPlugin\Review\PendingDraft;
use MerchantQuoteAgentPlugin\Review\ReviewFingerprint;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\NegotiationFixture;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\QuoteSnapshotFixture;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;

final class DraftSenderTest extends TestCase
{
    private const QUOTE_ID = '0190cccc0000700080000000000000cc';

    public function testAnOfferIsMergedCommentedAndMovedToReplied(): void
    {
        $open = QuoteSnapshotFixture::snapshot(state: 'open');
        $merchant = new FakeQuoteGateway([$open, QuoteSnapshotFixture::snapshot(state: 'in_review')]);
        $versions = new FakeDraftVersions(new FakeQuoteGateway([$open]));
        $store = new FakeReviewStore();

        $this->sender($versions, $store, $merchant)->send(
            new PendingDraft(self::record('0190aaaa0000700080000000000000aa', $open), $open, $versions->draft, false),
            ' We can offer 5%. ',
            new DraftEdits(),
            new Context(new AdminApiSource('user-1')),
        );

        self::assertSame(['0190aaaa0000700080000000000000aa'], $versions->merged);
        self::assertSame([QuoteTransition::Process, QuoteTransition::Sent], $merchant->transitions);
        self::assertSame(['We can offer 5%.'], $merchant->comments);
        self::assertSame('We can offer 5%.', $store->sent[0][1]);
    }

    public function testAStaleDraftIsNotSent(): void
    {
        $open = QuoteSnapshotFixture::snapshot();

        $this->expectException(DraftNotReviewable::class);

        $this->sender(
            new FakeDraftVersions(new FakeQuoteGateway([$open])),
            new FakeReviewStore(),
            new FakeQuoteGateway([$open]),
        )->send(
            new PendingDraft(self::record(null), $open, null, true),
            'x',
            new DraftEdits(),
            Context::createDefaultContext(),
        );
    }

    public function testSendRefusesAPriceIncreaseBeforeMergingOrCommenting(): void
    {
        $live = NegotiationFixture::snapshot(totalNet: 1000.0);
        $raised = NegotiationFixture::snapshot(totalNet: 1100.0);
        $merchant = new FakeQuoteGateway([$live]);
        $versions = new FakeDraftVersions(new FakeQuoteGateway([$raised]));
        $store = new FakeReviewStore();

        try {
            $this->sender($versions, $store, $merchant)->send(
                new PendingDraft(
                    self::record('0190aaaa0000700080000000000000aa', $live),
                    $live,
                    $versions->draft,
                    false,
                ),
                'Raise the price.',
                new DraftEdits(),
                new Context(new AdminApiSource('user-1')),
            );
            self::fail('A higher price was sent.');
        } catch (InvalidReviewRequest) {
            self::assertSame([], $versions->merged);
            self::assertSame([], $merchant->comments);
        }
    }

    public function testPreviewedEditsAndReplyEditsAreRecordedOnSend(): void
    {
        $open = QuoteSnapshotFixture::snapshot(state: 'open');
        $merchant = new FakeQuoteGateway([$open, QuoteSnapshotFixture::snapshot(state: 'in_review')]);
        $versions = new FakeDraftVersions(new FakeQuoteGateway([$open]));
        $store = new FakeReviewStore();
        $record = self::record('0190aaaa0000700080000000000000aa', $open);
        $record->replyToBuyer = 'Original reply';
        $record->sentChanges = ['editedByMerchant' => true];

        $this->sender($versions, $store, $merchant)->send(
            new PendingDraft($record, $open, $versions->draft, false),
            'Merchant wording',
            new DraftEdits(),
            new Context(new AdminApiSource('user-1')),
        );

        self::assertTrue($store->sent[0][2]['editedByMerchant']);
    }

    public function testLivePriceChangeAfterReviewOpenIsRefusedBeforeMerge(): void
    {
        $atOpen = NegotiationFixture::snapshot(totalNet: 1000.0);
        $changed = NegotiationFixture::snapshot(totalNet: 1050.0);
        $merchant = new FakeQuoteGateway([$changed]);
        $versions = new FakeDraftVersions(new FakeQuoteGateway([$atOpen]));
        $store = new FakeReviewStore();
        $record = self::record('0190aaaa0000700080000000000000aa');
        $record->reviewFingerprint = ReviewFingerprint::atDraft($atOpen, $atOpen);

        try {
            $this->sender($versions, $store, $merchant)->send(
                new PendingDraft($record, $atOpen, $versions->draft, false),
                'We can offer 5%.',
                new DraftEdits(),
                new Context(new AdminApiSource('user-1')),
            );
            self::fail('A live edit after review-open was overwritten.');
        } catch (DraftNotReviewable $caught) {
            self::assertSame('stale', $caught->reason);
        }

        self::assertSame([], $versions->merged);
        self::assertSame([], $merchant->comments);
    }

    private function sender(
        FakeDraftVersions $versions,
        FakeReviewStore $store,
        FakeQuoteGateway $merchant,
    ): DraftSender {
        $gateways = new class($merchant) implements ContextBoundGateways {
            public function __construct(
                private readonly FakeQuoteGateway $merchant,
            ) {}

            public function forContext(Context $context): FakeQuoteGateway
            {
                return $this->merchant;
            }
        };

        $connection = $this->createMock(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn(callable $work): mixed => $work());

        return new DraftSender($versions, $gateways, new DraftSendCompletion($store, new NullLogger()), $connection);
    }

    private static function record(?string $versionId, ?QuoteSnapshot $live = null): QuoteDecisionRecord
    {
        $record = new QuoteDecisionRecord();
        $record->id = 'rec';
        $record->quoteId = self::QUOTE_ID;
        $record->draftVersionId = $versionId;
        $record->reviewFingerprint = $live === null ? null : ReviewFingerprint::atDraft($live, $live);

        return $record;
    }
}
