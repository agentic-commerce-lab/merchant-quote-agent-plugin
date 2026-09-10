<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\History\CustomerSummary;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use PHPUnit\Framework\TestCase;

final class HistoryLoopTest extends TestCase
{
    public function testBriefIsLoadedOnceFromOwnCustomerBeforeFirstProposal(): void
    {
        $h = new HistoryProposerHarness([HistoryProposerHarness::offer()]);
        self::assertSame(5.0, $h->propose()->offer?->price->discountPercent);
        self::assertSame(['cust-1'], $h->factory->boundTo);
        self::assertSame(1, $h->history->summaryCalls);
        self::assertSame(1, $h->spy->calls);
        self::assertStringContainsString('INTERNAL', $h->spy->userPrompts[0]);
        // The serviced quote must be handed to the factory for exclusion, or
        // "history" silently includes the quote under negotiation and the model
        // can read its own applied discount back as an unspent precedent.
        self::assertSame([NegotiationFixture::snapshot()->identity->quoteId], $h->factory->excludedQuotes);
        self::assertStringContainsString('lineItemId | productId | label', $h->spy->userPrompts[0]);
        self::assertStringContainsString('line-1 | prod-1 | Widget', $h->spy->userPrompts[0]);
        self::assertNotNull($h->writer->drafts[0]->historyReads);
    }

    public function testRequestedBlockIsAppendedAndIntermediateTermsAreDiscarded(): void
    {
        $h = new HistoryProposerHarness([HistoryProposerHarness::request(), HistoryProposerHarness::offer()]);
        self::assertSame(5.0, $h->propose()->offer?->price->discountPercent);
        self::assertSame(1, $h->history->quoteCalls);
        self::assertSame(2, $h->spy->calls);
        self::assertStringStartsWith($h->spy->userPrompts[0], $h->spy->userPrompts[1]);
        self::assertStringContainsString('YOUR AUTHORITY', $h->spy->userPrompts[1]);
        self::assertSame($h->spy->systemPrompts[0], $h->spy->systemPrompts[1]);
        $round = $h->writer->drafts[0]->historyReads['rounds'][0];
        self::assertStringEndsWith($round['result'], $h->spy->userPrompts[1]);
    }

    public function testTwoReadsAllowAThirdAndFinalProposal(): void
    {
        $h = new HistoryProposerHarness([
            HistoryProposerHarness::request(),
            HistoryProposerHarness::request('orders'),
            HistoryProposerHarness::offer(),
        ]);
        self::assertNotNull($h->propose()->offer);
        self::assertSame(3, $h->spy->calls);
        self::assertSame(1, $h->history->quoteCalls);
        self::assertSame(1, $h->history->orderCalls);
        self::assertCount(2, $h->writer->drafts[0]->historyReads['rounds']);
        self::assertStringStartsWith($h->spy->userPrompts[1], $h->spy->userPrompts[2]);
    }

    public function testThirdRequestEscalatesWithoutAnUnusedReadAndRetainsRawRequest(): void
    {
        $h = new HistoryProposerHarness([
            HistoryProposerHarness::request(),
            HistoryProposerHarness::request(),
            HistoryProposerHarness::request('product_purchases', 'prod-1'),
        ]);
        $answer = $h->propose();
        self::assertNull($answer->offer);
        self::assertSame(QuoteEscalationReason::NeedsHumanReview, $answer->escalation);
        self::assertSame(3, $h->spy->calls);
        self::assertSame(2, $h->history->quoteCalls);
        self::assertSame([], $h->history->products);
        self::assertCount(2, $h->writer->drafts[0]->historyReads['rounds']);
        self::assertStringContainsString('product_purchases', $h->writer->drafts[0]->rawProposal);
        self::assertStringContainsString('2', $h->writer->drafts[0]->violations[0]);
    }

    public function testHistoryRequestAlsoBeatsEscalation(): void
    {
        $h = new HistoryProposerHarness([
            '{"action":"escalate","historyRequest":{"kind":"orders"}}',
            HistoryProposerHarness::offer(),
        ]);
        self::assertNotNull($h->propose()->offer);
        self::assertSame(2, $h->spy->calls);
    }

    public function testUnavailableHistoryLeavesNoHeadingAndAuditsReason(): void
    {
        $h = new HistoryProposerHarness(
            [HistoryProposerHarness::offer()],
            new InMemoryCustomerHistory(CustomerSummary::unavailable('customer is missing')),
        );
        self::assertNotNull($h->propose()->offer);
        self::assertSame(1, $h->spy->calls);
        self::assertStringNotContainsString('INTERNAL', $h->spy->userPrompts[0]);
        self::assertSame('customer is missing', $h->writer->drafts[0]->historyReads['reason']);
    }

    public function testOffQuoteRefusalConsumesRoundWithoutReading(): void
    {
        $h = new HistoryProposerHarness([
            HistoryProposerHarness::request('product_purchases', 'foreign-product'),
            HistoryProposerHarness::offer(),
        ]);
        self::assertNotNull($h->propose()->offer);
        self::assertSame([], $h->history->products);
        self::assertStringContainsString('not on this quote', $h->spy->userPrompts[1]);
        self::assertCount(1, $h->writer->drafts[0]->historyReads['rounds']);
    }

    public function testHistoryCannotExpandPriceAuthority(): void
    {
        $h = new HistoryProposerHarness([HistoryProposerHarness::request(), HistoryProposerHarness::offer(40)]);
        self::assertSame(QuoteEscalationReason::ProposalRejected, $h->propose()->escalation);
    }
}
