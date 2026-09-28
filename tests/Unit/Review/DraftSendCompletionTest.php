<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Review\DraftSendCompletion;
use MerchantQuoteAgentPlugin\Review\DraftSendFailed;
use MerchantQuoteAgentPlugin\Review\PendingDraft;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\NegotiationFixture;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\QuoteSnapshotFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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

    /**
     * An acknowledgement has no version, yet it must still reach replied:
     * the buyer's comment moved the quote to a renegotiation state, and only
     * the reply moves it back to where the buyer can accept (PassedOver).
     */
    #[DataProvider('renegotiationStates')]
    public function testAnAcknowledgementIsCommentedAndMovedToRepliedWithoutAClaim(string $state): void
    {
        $live = QuoteSnapshotFixture::snapshot(state: $state);
        $gateway = new FakeQuoteGateway([$live]);
        $store = new FakeReviewStore();

        (new DraftSendCompletion($store, new NullLogger()))->complete(
            new PendingDraft(self::draftWithoutPrices('acknowledged'), $live, null, false),
            $gateway,
            'Thanks, the quote stands.',
            false,
        );

        self::assertSame(['Thanks, the quote stands.'], $gateway->comments);
        self::assertSame([QuoteTransition::AdminResend], $gateway->transitions);
        self::assertNull($store->sent[0][2] ?? null, 'An acknowledgement sends no price changes.');
    }

    /** @return iterable<string, array{string}> */
    public static function renegotiationStates(): iterable
    {
        yield 'trunk' => ['change_requested'];
        yield '6.7.12' => ['reopen'];
    }

    /** A clarification only asks its question, drafted or not: the buyer owes the next move. */
    public function testAClarificationIsCommentedWithoutATransition(): void
    {
        $live = QuoteSnapshotFixture::snapshot(state: 'change_requested');
        $gateway = new FakeQuoteGateway([$live]);

        (new DraftSendCompletion(new FakeReviewStore(), new NullLogger()))->complete(
            new PendingDraft(self::draftWithoutPrices('clarified'), $live, null, false),
            $gateway,
            'Which colour?',
            false,
        );

        self::assertSame(['Which colour?'], $gateway->comments);
        self::assertSame([], $gateway->transitions);
    }

    private static function draftWithoutPrices(string $outcome): QuoteDecisionRecord
    {
        $record = new QuoteDecisionRecord();
        $record->id = 'rec-1';
        $record->quoteId = 'q1';
        $record->outcome = $outcome;

        return $record;
    }
}
