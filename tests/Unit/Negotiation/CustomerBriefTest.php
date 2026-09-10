<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\History\CustomerSummary;
use MerchantQuoteAgentPlugin\Bridge\Data\History\OrderStats;
use MerchantQuoteAgentPlugin\Bridge\Data\History\QuoteStats;
use MerchantQuoteAgentPlugin\Negotiation\CustomerBrief;
use PHPUnit\Framework\TestCase;

/**
 * Who the agent is negotiating with. Sibling of AuthorityBriefTest, and it
 * asserts the same two kinds of thing: what must be stated, and what must NOT
 * appear.
 */
final class CustomerBriefTest extends TestCase
{
    private static function summary(): CustomerSummary
    {
        return new CustomerSummary(
            quotes: new QuoteStats(
                seen: 12,
                converted: 3,
                lost: 5,
                offersMade: 8,
                offersAccepted: 3,
                lastGrantedDiscountPercent: 4.5,
            ),
            orders: new OrderStats(
                count: 6,
                lifetimeNet: 128400.0,
                lastOrderAt: new \DateTimeImmutable('2026-07-14 09:30:00'),
                currencyIso: 'EUR',
            ),
        );
    }

    public function testTheQuoteRecordIsStated(): void
    {
        $brief = CustomerBrief::of(self::summary());

        self::assertStringContainsString('12 recent quotes', $brief);
        self::assertStringContainsString('3 became orders', $brief);
        self::assertStringContainsString('5 ended without a deal', $brief);
    }

    public function testTheLatestRecordedPassReductionIsStated(): void
    {
        // The number describes a recorded pass, not the cumulative discount
        // the buyer ultimately received.
        self::assertStringContainsString('4.50%', CustomerBrief::of(self::summary()));
    }

    public function testTheOrderRecordIsStated(): void
    {
        $brief = CustomerBrief::of(self::summary());

        self::assertStringContainsString('6 orders', $brief);
        self::assertStringContainsString('128400.00 EUR net', $brief);
        self::assertStringContainsString('2026-07-14', $brief);
    }

    public function testTheBlockIsMarkedInternal(): void
    {
        // The negotiate call writes the buyer-facing `message` in the same
        // response, so the marker travels with the data rather than living only
        // in the prompt file.
        self::assertStringContainsString('INTERNAL', CustomerBrief::of(self::summary()));
    }

    public function testANewAccountSaysSoRatherThanRenderingZeroes(): void
    {
        // "0 recent quotes, 0 orders, lifetime 0.00" reads like a data failure.
        // Describe the absent records without inferring whether this is first contact.
        $brief = CustomerBrief::of(new CustomerSummary());

        self::assertStringContainsString('no quotes and no orders recorded', $brief);
        self::assertStringNotContainsString('lifetime', $brief);
    }

    public function testAnUnavailableSummaryRendersNothingAtAll(): void
    {
        // An empty section invites the model to speculate about why. The reason
        // goes to the audit record instead -- see DecisionRecorder::recordHistory().
        self::assertSame('', CustomerBrief::of(CustomerSummary::unavailable('no customer id')));
    }

    public function testAnAccountWeNeverPricedOmitsTheGrantLine(): void
    {
        // Null means "we don't know", never "we gave nothing". Rendering 0.00%
        // would tell the model we have refused this buyer before.
        $summary = new CustomerSummary(quotes: new QuoteStats(seen: 2, lastGrantedDiscountPercent: null));

        self::assertStringNotContainsString('granted', CustomerBrief::of($summary));
    }

    public function testAGenuineZeroGrantStillRendersUnlikeAnUnknownOne(): void
    {
        // null means no recorded pass reduction; 0.0 means the pass recorded
        // no reduction. Neither proves delivery. The live test shop's
        // merchant_quote_agent_decision table has both: a `!==null` check is
        // required, a truthy check would silently drop this branch since 0.0 is
        // falsy in PHP.
        $summary = new CustomerSummary(quotes: new QuoteStats(seen: 2, lastGrantedDiscountPercent: 0.0));

        self::assertStringContainsString('0.00%', CustomerBrief::of($summary));
        self::assertStringContainsString(
            'latest recorded pass reduced that pass’s opening total by',
            CustomerBrief::of($summary),
        );
    }
}
