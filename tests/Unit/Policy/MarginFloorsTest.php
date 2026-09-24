<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Policy\GoodsFactor;
use MerchantQuoteAgentPlugin\Policy\MarginFloors;
use PHPUnit\Framework\TestCase;

final class MarginFloorsTest extends TestCase
{
    public static function line(
        string $id,
        float $unit,
        int $quantity = 1,
        ?string $productId = null,
    ): QuoteLineSnapshot {
        return new QuoteLineSnapshot(
            identity: new QuoteLineIdentity($id, $id, $productId ?? 'prod-' . $id),
            quantity: $quantity,
            unitPriceNet: $unit,
            totalNet: $unit * $quantity,
        );
    }

    /** @param list<QuoteLineSnapshot> $lines */
    public static function quote(array $lines): QuoteSnapshot
    {
        $total = array_sum(array_map(static fn(QuoteLineSnapshot $l): float => $l->totalNet, $lines));

        return new QuoteSnapshot('EUR', $total, $lines, new QuoteLifecycle('open'));
    }

    public function testTheUsersWorkedExample(): void
    {
        // Listed at 120, purchase price 100, minimum margin 10%: 110.
        $floors = MarginFloors::of(self::quote([self::line('a', 120.0)]), ['prod-a' => 100.0], 10.0);

        self::assertSame(['a' => 110.0], $floors);
    }

    public function testTheFloorRoundsUpToTheCent(): void
    {
        // 33.33 × 1.10 = 36.663 -> 36.67, never 36.66.
        $floors = MarginFloors::of(self::quote([self::line('a', 50.0)]), ['prod-a' => 33.33], 10.0);

        self::assertSame(36.67, $floors['a']);
    }

    public function testAnExactCentIsNotRoundedUpByFloatNoise(): void
    {
        // 100 × 1.1 is 110.00000000000001 in floating point.
        $floors = MarginFloors::of(self::quote([self::line('a', 200.0)]), ['prod-a' => 100.0], 10.0);

        self::assertSame(110.0, $floors['a']);
    }

    public function testALinePricedBelowItsFloorIsFlooredAtItsOwnPrice(): void
    {
        // Never raised: the floor for a loss leader is the price it already has.
        $floors = MarginFloors::of(self::quote([self::line('a', 90.0)]), ['prod-a' => 100.0], 10.0);

        self::assertSame(90.0, $floors['a']);
    }

    public function testAnExistingQuoteDiscountCountsTowardsTheLivePrice(): void
    {
        // Two lines of 100 and a -10 quote-discount line: every line costs the
        // buyer 95 today, so a floor of 99 is already undercut and stays at 95.
        $floors = MarginFloors::of(
            self::quote([self::line('a', 100.0), self::line('b', 100.0), self::line('discount', -10.0, 1, null)]),
            ['prod-a' => 50.0, 'prod-b' => 90.0],
            10.0,
        );

        self::assertSame(['a' => 55.0, 'b' => 95.0], $floors);
    }

    public function testTheLiveCapRoundsDownSoItNeverExceedsTodaysPrice(): void
    {
        // 10.01 × 0.95 = 9.5095 today: capped at 9.50, never rounded up to 9.51.
        $floors = MarginFloors::of(
            self::quote([self::line('b', 10.01, 100), self::line('d', -50.05, 1, null)]),
            ['prod-b' => 10.0],
            10.0,
        );

        self::assertSame(['b' => 9.5], $floors);
    }

    public function testLinesWithoutAPurchasePriceHaveNoFloor(): void
    {
        $floors = MarginFloors::of(
            self::quote([self::line('a', 100.0), self::line('b', 100.0)]),
            ['prod-a' => 50.0],
            0.0,
        );

        self::assertSame(['a' => 50.0], $floors);
    }

    public function testTheGoodsFactorIsTheShareTheNegativeLinesLeave(): void
    {
        self::assertSame(1.0, GoodsFactor::of([]));
        self::assertSame(1.0, GoodsFactor::of([self::line('a', 100.0, 3)]));
        self::assertSame(0.95, GoodsFactor::of([self::line('a', 100.0, 2), self::line('d', -10.0)]));
    }
}
