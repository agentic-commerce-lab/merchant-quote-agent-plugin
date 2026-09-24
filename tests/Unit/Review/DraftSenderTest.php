<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use MerchantQuoteAgentPlugin\Bridge\ContextBoundGateways;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Review\DraftEdits;
use MerchantQuoteAgentPlugin\Review\DraftNotReviewable;
use MerchantQuoteAgentPlugin\Review\DraftSendCompletion;
use MerchantQuoteAgentPlugin\Review\DraftSender;
use MerchantQuoteAgentPlugin\Review\PendingDraft;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\QuoteSnapshotFixture;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;

final class DraftSenderTest extends TestCase
{
    public function testAnOfferIsMergedCommentedAndMovedToReplied(): void
    {
        $open = QuoteSnapshotFixture::snapshot(state: 'open');
        $merchant = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot(state: 'in_review')]);
        $versions = new FakeDraftVersions(new FakeQuoteGateway([$open]));
        $store = new FakeReviewStore();

        self::sender($versions, $store, $merchant)
            ->send(
                new PendingDraft(self::record('0190aaaa0000700080000000000000aa'), $open, $versions->draft, false),
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

        self::sender(
            new FakeDraftVersions(new FakeQuoteGateway([$open])),
            new FakeReviewStore(),
            new FakeQuoteGateway([$open]),
        )
            ->send(
                new PendingDraft(self::record(null), $open, null, true),
                'x',
                new DraftEdits(),
                Context::createDefaultContext(),
            );
    }

    public function testPreviewedEditsAndReplyEditsAreRecordedOnSend(): void
    {
        $open = QuoteSnapshotFixture::snapshot(state: 'open');
        $merchant = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot(state: 'in_review')]);
        $versions = new FakeDraftVersions(new FakeQuoteGateway([$open]));
        $store = new FakeReviewStore();
        $record = self::record('0190aaaa0000700080000000000000aa');
        $record->replyToBuyer = 'Original reply';
        $record->sentChanges = ['editedByMerchant' => true];

        self::sender($versions, $store, $merchant)
            ->send(
                new PendingDraft($record, $open, $versions->draft, false),
                'Merchant wording',
                new DraftEdits(),
                new Context(new AdminApiSource('user-1')),
            );

        self::assertTrue($store->sent[0][2]['editedByMerchant']);
    }

    private static function sender(
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

        return new DraftSender($versions, $gateways, new DraftSendCompletion($store, new NullLogger()));
    }

    private static function record(?string $versionId): QuoteDecisionRecord
    {
        $record = new QuoteDecisionRecord();
        $record->id = 'rec';
        $record->quoteId = 'q1';
        $record->draftVersionId = $versionId;

        return $record;
    }
}
