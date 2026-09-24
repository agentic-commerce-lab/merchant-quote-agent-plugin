<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLinePrice;
use MerchantQuoteAgentPlugin\Policy\MarginFloorClamp;
use PHPUnit\Framework\TestCase;

final class MarginFloorClampTest extends TestCase
{
    private static function quoteWide(float $percent): ProposedOffer
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
    private static function prices(?ProposedOffer $offer): array
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
        $clamped = MarginFloorClamp::clamp(self::quoteWide(15.0), [MarginFloorsTest::line('a', 120.0)], ['a' => 110.0]);

        self::assertSame(['a' => 110.0], self::prices($clamped));
    }

    public function testEachLineGoesAsLowAsItsOwnFloorAllows(): void
    {
        $clamped = MarginFloorClamp::clamp(
            self::quoteWide(15.0),
            [MarginFloorsTest::line('a', 100.0), MarginFloorsTest::line('b', 100.0)],
            ['a' => 55.0, 'b' => 99.0],
        );

        self::assertSame(['a' => 85.0, 'b' => 99.0], self::prices($clamped));
    }

    public function testNothingBindingLeavesTheOfferAlone(): void
    {
        self::assertNull(MarginFloorClamp::clamp(
            self::quoteWide(5.0),
            [MarginFloorsTest::line('a', 120.0)],
            ['a' => 110.0],
        ));
        self::assertNull(MarginFloorClamp::clamp(self::quoteWide(50.0), [MarginFloorsTest::line('a', 120.0)], []));
    }

    public function testAPerLineOfferIsRaisedToTheFloor(): void
    {
        $clamped = MarginFloorClamp::clamp(
            self::perLine(['a' => 80.0]),
            [MarginFloorsTest::line('a', 100.0)],
            ['a' => 88.0],
        );

        self::assertSame(['a' => 88.0], self::prices($clamped));
    }

    public function testUnnamedLinesCarryTheOldQuoteDiscountIntoTheirOwnPrice(): void
    {
        // Round one wrote 5% off the quote (the -10 line). Round two names only
        // line a. Once floored, the discount is reset, so b must keep its 95.
        $clamped = MarginFloorClamp::clamp(
            self::perLine(['a' => 90.0]),
            [
                MarginFloorsTest::line('a', 100.0),
                MarginFloorsTest::line('b', 100.0),
                MarginFloorsTest::line('d', -10.0, 1, null),
            ],
            ['a' => 88.0, 'b' => 95.0],
        );

        self::assertSame(['a' => 88.0, 'b' => 95.0], self::prices($clamped));
    }

    public function testAQuoteWidePercentReplacesTheOldDiscountRatherThanStacking(): void
    {
        // A percentage write replaces the existing discount, so 10% of 100 is
        // 90, not 90 × 0.95. The floor of 92 binds.
        $clamped = MarginFloorClamp::clamp(
            self::quoteWide(10.0),
            [MarginFloorsTest::line('a', 100.0), MarginFloorsTest::line('d', -5.0, 1, null)],
            ['a' => 92.0],
        );

        self::assertSame(['a' => 92.0], self::prices($clamped));
    }
}
