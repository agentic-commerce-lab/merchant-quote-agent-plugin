<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\NoCustomerHistory;
use PHPUnit\Framework\TestCase;

/**
 * A quote whose customer cannot be read must degrade to "no history" and say
 * so, never to an unfiltered read. `available: false` plus a reason is what
 * puts that on the audit record instead of leaving it silent.
 */
final class NoCustomerHistoryTest extends TestCase
{
    public function testEveryReadIsEmptyAndTheReasonSurvives(): void
    {
        $history = new NoCustomerHistory('the quote carries no customer id');

        $summary = $history->summary();

        self::assertFalse($summary->available);
        self::assertSame('the quote carries no customer id', $summary->unavailableReason);
        self::assertSame(0, $summary->quotes->seen);
        self::assertSame(0, $summary->orders->count);
        self::assertSame(0.0, $summary->orders->lifetimeNet);
        self::assertNull($summary->orders->lastOrderAt);
        self::assertSame([], $history->quotes());
        self::assertSame([], $history->orders()->recent);
        self::assertSame([], $history->productPurchases('prod-1'));
    }
}
