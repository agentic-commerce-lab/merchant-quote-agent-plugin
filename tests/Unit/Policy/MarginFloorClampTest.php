<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLinePrice;
use MerchantQuoteAgentPlugin\Policy\MarginFloorClamp;
use PHPUnit\Framework\TestCase;

/**
 * First-round cases: the quote has no baseline yet, so the reference the
 * conversion anchors on IS the live quote. Later rounds live in
 * MarginFloorClampAnchoringTest.
 */
final class MarginFloorClampTest extends TestCase
{
    public static function quoteWide(float $percent): ProposedOffer
    {
        return new ProposedOffer(orderTotalNet: 0.0, price: new OfferedPrice(discountPercent: $percent));
    }

    /** @param array<string, float> $prices */
    private static function perLine(array $prices): ProposedOffer
    {
        $lines = [];
        foreach ($prices as $id => $price) {
            $lines[] = new QuoteLinePrice($id, $price);
        }

        return new ProposedOffer(orderTotalNet: 0.0, price: new OfferedPrice(linePricesNet: $lines));
    }

    /** @return array<string, float> */
    public static function prices(?ProposedOffer $offer): array
    {
        self::assertNotNull($offer);
        self::assertNull($offer->price->discountPercent);
        $prices = [];
        foreach ($offer->price->linePricesNet ?? [] as $price) {
            $prices[$price->lineItemId] = $price->unitPriceNet;
        }

        return $prices;
    }

    public function testTheUsersWorkedExample(): void
    {
        // 120 listed, 15% asked -> 102, floor 110: written at 110.
        $live = [MarginFloorsTest::line('a', 120.0)];
        $clamped = MarginFloorClamp::clamp(self::quoteWide(15.0), $live, $live, ['a' => 110.0]);

        self::assertSame(['a' => 110.0], self::prices($clamped));
    }

    public function testEachLineGoesAsLowAsItsOwnFloorAllows(): void
    {
        $live = [MarginFloorsTest::line('a', 100.0), MarginFloorsTest::line('b', 100.0)];
        $clamped = MarginFloorClamp::clamp(self::quoteWide(15.0), $live, $live, ['a' => 55.0, 'b' => 99.0]);

        self::assertSame(['a' => 85.0, 'b' => 99.0], self::prices($clamped));
    }

    public function testNothingBindingLeavesTheOfferAlone(): void
    {
        $live = [MarginFloorsTest::line('a', 120.0)];
        self::assertNull(MarginFloorClamp::clamp(self::quoteWide(5.0), $live, $live, ['a' => 110.0]));
        self::assertNull(MarginFloorClamp::clamp(self::quoteWide(50.0), $live, $live, []));
    }

    public function testAPerLineOfferIsRaisedToTheFloor(): void
    {
        $live = [MarginFloorsTest::line('a', 100.0)];
        $clamped = MarginFloorClamp::clamp(self::perLine(['a' => 80.0]), $live, $live, ['a' => 88.0]);

        self::assertSame(['a' => 88.0], self::prices($clamped));
    }

    public function testUnnamedLinesCarryTheOldQuoteDiscountIntoTheirOwnPrice(): void
    {
        // Round one wrote 5% off the quote (the -10 line). Round two names only
        // line a. Once floored, the discount is reset, so b must keep its 95.
        $live = [
            MarginFloorsTest::line('a', 100.0),
            MarginFloorsTest::line('b', 100.0),
            MarginFloorsTest::line('d', -10.0, 1, null),
        ];
        $clamped = MarginFloorClamp::clamp(self::perLine(['a' => 90.0]), $live, $live, ['a' => 88.0, 'b' => 95.0]);

        self::assertSame(['a' => 88.0, 'b' => 95.0], self::prices($clamped));
    }

    public function testAQuoteWidePercentReplacesTheOldDiscountRatherThanStacking(): void
    {
        // A percentage write replaces the existing discount, so 10% of 100 is
        // 90, not 90 × 0.95. The floor of 92 binds.
        $live = [MarginFloorsTest::line('a', 100.0), MarginFloorsTest::line('d', -5.0, 1, null)];
        $clamped = MarginFloorClamp::clamp(self::quoteWide(10.0), $live, $live, ['a' => 92.0]);

        self::assertSame(['a' => 92.0], self::prices($clamped));
    }

    public function testUnflooredLinesRoundDownSoTheTotalNeverRises(): void
    {
        // Per-unit rounding to the nearest cent would write b at 9.51
        // (10.01 × 0.95 = 9.5095) and lift the total by 0.05 once the discount
        // is reset; rounding down writes 9.50 and the total can only fall.
        $live = [
            MarginFloorsTest::line('a', 100.0),
            MarginFloorsTest::line('b', 10.01, 100),
            MarginFloorsTest::line('d', -55.05, 1, null),
        ];
        $prices = self::prices(MarginFloorClamp::clamp(self::perLine(['a' => 90.0]), $live, $live, ['a' => 95.0]));

        self::assertSame(['a' => 95.0, 'b' => 9.5], $prices);
        $written = $prices['a'] + ($prices['b'] * 100); // quantities 1 and 100
        $effective = array_sum(array_map(static fn($line): float => $line->unitPriceNet * $line->quantity, $live));
        self::assertLessThanOrEqual($effective, $written);

        // Quote-wide 10%: b has no floor and lands at 29.997, written at 29.99.
        $live = [MarginFloorsTest::line('a', 100.0), MarginFloorsTest::line('b', 33.33)];
        $clamped = MarginFloorClamp::clamp(self::quoteWide(10.0), $live, $live, ['a' => 95.0]);

        self::assertSame(['a' => 95.0, 'b' => 29.99], self::prices($clamped));
    }
}
