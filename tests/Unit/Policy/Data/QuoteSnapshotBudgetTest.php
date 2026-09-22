<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy\Data;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;
use PHPUnit\Framework\TestCase;

/** The clamp a quote-level budget ("max cost 2500") goes through. */
final class QuoteSnapshotBudgetTest extends TestCase
{
    private static function snapshot(?float $buyerTargetNet = null): QuoteSnapshot
    {
        return new QuoteSnapshot(
            currencyIso: 'EUR',
            totalNet: 1000.0,
            lines: [],
            lifecycle: new QuoteLifecycle('open'),
            buyerTargetNet: $buyerTargetNet,
        );
    }

    public function testNoBudgetLeavesTheTargetExactlyAsItWas(): void
    {
        // Not `totalNet`: a quote nobody made a quote-level ask on has a NULL
        // target, and MoneyMath::requestedDiscount() answers null for it.
        // Filling it in here would report every such escalation as a 0% ask.
        self::assertNull(self::snapshot()->cappedAtBudget(null)->buyerTargetNet);
        self::assertSame(900.0, self::snapshot(900.0)->cappedAtBudget(null)->buyerTargetNet);
    }

    public function testABudgetBecomesTheQuoteLevelTarget(): void
    {
        self::assertSame(750.0, self::snapshot()->cappedAtBudget(750.0)->buyerTargetNet);
    }

    public function testABudgetAtOrAboveTheQuotedTotalAsksForNothing(): void
    {
        self::assertSame(1000.0, self::snapshot()->cappedAtBudget(1200.0)->buyerTargetNet);
    }

    public function testTheDeeperOfTwoAsksInOneCommentWins(): void
    {
        // Per-line targets already put the target at 900; a budget of 750 on
        // top of them is the buyer asking for more, not less.
        self::assertSame(750.0, self::snapshot(900.0)->cappedAtBudget(750.0)->buyerTargetNet);
        self::assertSame(900.0, self::snapshot(900.0)->cappedAtBudget(950.0)->buyerTargetNet);
    }
}
