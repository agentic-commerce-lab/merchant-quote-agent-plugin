<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Review\DraftSendCompletion;
use MerchantQuoteAgentPlugin\Review\DraftSendFailed;
use MerchantQuoteAgentPlugin\Review\PendingDraft;
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
        $gateway->method('addComment')->willThrowException($error);
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('error')
            ->with(
                self::stringContains('after merging'),
                self::callback(static fn(array $context): bool => $context['exception'] === $error),
            );
        $store = new FakeReviewStore();

        try {
            (new DraftSendCompletion($store, $logger))->complete($pending, $gateway, 'Reply', null);
            self::fail('The failed send returned normally.');
        } catch (DraftSendFailed $caught) {
            self::assertSame($error, $caught->getPrevious());
        }

        self::assertSame([], $store->sent);
    }
}
