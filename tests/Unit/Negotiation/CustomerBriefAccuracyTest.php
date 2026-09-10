<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\History\CustomerSummary;
use MerchantQuoteAgentPlugin\Bridge\Data\History\OrderStats;
use MerchantQuoteAgentPlugin\Bridge\Data\History\QuoteStats;
use MerchantQuoteAgentPlugin\Negotiation\CustomerBrief;
use PHPUnit\Framework\TestCase;

final class CustomerBriefAccuracyTest extends TestCase
{
    public function testProposalPassesAndAcceptedQuotesHaveDifferentDenominators(): void
    {
        $brief = CustomerBrief::of(
            new CustomerSummary(quotes: new QuoteStats(seen: 1, offersMade: 2, offersAccepted: 1)),
        );
        self::assertStringContainsString(
            '2 authorized proposal passes across this account; 1 accepted quotes had an authorized proposal',
            $brief,
        );
        self::assertStringNotContainsString('you have made', $brief);
    }

    public function testUnknownLifetimeMoneyPreservesOrderCountAndDate(): void
    {
        $brief = CustomerBrief::of(
            new CustomerSummary(orders: new OrderStats(
                count: 2,
                lifetimeNet: null,
                lastOrderAt: new \DateTimeImmutable('2026-09-08'),
                unavailableReason: 'order currencies are mixed or unknown',
            )),
        );
        self::assertStringContainsString('2 orders', $brief);
        self::assertStringContainsString('lifetime net unavailable: order currencies are mixed or unknown', $brief);
        self::assertStringContainsString('2026-09-08', $brief);
        self::assertStringNotContainsString('0.00', $brief);
    }
}
