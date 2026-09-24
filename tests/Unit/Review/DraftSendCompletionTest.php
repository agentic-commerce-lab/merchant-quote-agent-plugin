<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Review\DraftSendCompletion;
use MerchantQuoteAgentPlugin\Review\DraftSendFailed;
use MerchantQuoteAgentPlugin\Review\PendingDraft;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\NegotiationFixture;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\QuoteSnapshotFixture;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class DraftSendCompletionTest extends TestCase
{
    public function testFailureAfterMergeIsLoggedAndLeavesTheReviewPending(): void
    {
        $record = new QuoteDecisionRecord();
        $record->id = 'rec-1';
        $record->quoteId = 'quote-1';
        $record->draftVersionId = '0190aaaa0000700080000000000000aa';
        $live = QuoteSnapshotFixture::snapshot(state: 'in_review');
        $pending = new PendingDraft($record, $live, null, false);
        $error = new \RuntimeException('comment failed');
        $gateway = $this->createMock(QuoteGatewayInterface::class);
        $gateway->method('fetchSnapshot')->willReturn($live);
        $gateway->method('addComment')->willThrowException($error);
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('error')
            ->with(
                self::stringContains('Inspect the quote'),
                self::callback(static fn(array $context): bool => $context['exception'] === $error),
            );
        $store = new FakeReviewStore();

        try {
            (new DraftSendCompletion($store, $logger))->complete($pending, $gateway, 'Reply', false);
            self::fail('The failed send returned normally.');
        } catch (DraftSendFailed $caught) {
            self::assertSame($error, $caught->getPrevious());
        }

        self::assertSame([], $store->sent);
    }

    public function testSentTotalsAreReadFromLiveAfterPublication(): void
    {
        $record = new QuoteDecisionRecord();
        $record->id = 'rec-1';
        $record->quoteId = 'q1';
        $record->draftVersionId = '0190aaaa0000700080000000000000aa';
        $pending = new PendingDraft($record, NegotiationFixture::snapshot(state: 'in_review'), null, false);
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot(state: 'in_review', totalNet: 950.0)]);
        $store = new FakeReviewStore();

        (new DraftSendCompletion($store, $this->createMock(LoggerInterface::class)))->complete(
            $pending,
            $gateway,
            'Reply',
            true,
        );

        self::assertSame(950.0, $store->sent[0][2]['totalNet']);
        self::assertTrue($store->sent[0][2]['editedByMerchant']);
    }

    public function testAuditFailureAfterCommentLeavesAPublishingMarker(): void
    {
        $record = new QuoteDecisionRecord();
        $record->id = 'rec-1';
        $record->quoteId = 'q1';
        $record->draftVersionId = '0190aaaa0000700080000000000000aa';
        $pending = new PendingDraft($record, QuoteSnapshotFixture::snapshot(state: 'in_review'), null, false);
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot(state: 'in_review')]);
        $store = new FakeReviewStore();
        $failure = new \RuntimeException('audit unavailable');
        $store->markSentThrows = $failure;

        try {
            (new DraftSendCompletion($store, $this->createMock(LoggerInterface::class)))->complete(
                $pending,
                $gateway,
                'Reply',
                false,
            );
            self::fail('The failed audit write returned normally.');
        } catch (DraftSendFailed $caught) {
            self::assertSame($failure, $caught->getPrevious());
        }

        self::assertSame(['Reply'], $gateway->comments);
        self::assertSame([['rec-1', 0, false]], $store->publishing);
    }
}
