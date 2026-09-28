<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLinePrice;
use MerchantQuoteAgentPlugin\Policy\Data\Rounding;
use MerchantQuoteAgentPlugin\Policy\Data\RoundingMode;
use MerchantQuoteAgentPlugin\Policy\Data\RoundingSkip;
use MerchantQuoteAgentPlugin\Policy\DiscountRounding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DiscountRoundingTest extends TestCase
{
    private static function limits(RoundingMode $mode = RoundingMode::DiscountPercent, ?float $step = 0.5): QuoteLimits
    {
        return new QuoteLimits(maxDiscountPercent: 10.0, validityDays: 14, roundingMode: $mode, roundingStep: $step);
    }

    private static function offer(float $percent): ProposedOffer
    {
        return new ProposedOffer(orderTotalNet: 1000.0, price: new OfferedPrice(discountPercent: $percent));
    }

    public function testTheModelsPercentageIsFlooredToTheStep(): void
    {
        [$offer, $rounding] = DiscountRounding::offer(self::limits(), self::offer(7.34), null, 0.0);

        self::assertSame(7.0, $offer->price->discountPercent);
        self::assertEquals(new Rounding(RoundingMode::DiscountPercent, 0.5, 7.34, 7.0, null), $rounding);
    }

    public function testAPercentageAlreadyOnTheStepStaysExactlyThere(): void
    {
        [$offer, $rounding] = DiscountRounding::offer(self::limits(), self::offer(7.5), null, 0.0);

        self::assertSame(7.5, $offer->price->discountPercent);
        self::assertNull($rounding?->skipped);
    }

    public function testRoundingToNothingWritesTheUnroundedOffer(): void
    {
        [$offer, $rounding] = DiscountRounding::offer(self::limits(), self::offer(0.3), null, 0.0);

        self::assertSame(0.3, $offer->price->discountPercent);
        self::assertSame(RoundingSkip::ToZero, $rounding?->skipped);
        self::assertSame(0.0, $rounding?->rounded);
    }

    public function testTheBuyersOwnFigureIsNeverRounded(): void
    {
        [$offer, $rounding] = DiscountRounding::offer(self::limits(), self::offer(7.34), 7.34, 0.0);

        self::assertSame(7.34, $offer->price->discountPercent);
        self::assertSame(RoundingSkip::BuyerFigure, $rounding?->skipped);
    }

    public function testRoundingBelowWhatTheBuyerAlreadyHoldsWritesTheUnroundedOffer(): void
    {
        // Rounded, 7.0% would price a line above the 7.2% the buyer holds.
        [$offer, $rounding] = DiscountRounding::offer(self::limits(), self::offer(7.34), null, 7.2);

        self::assertSame(7.34, $offer->price->discountPercent);
        self::assertSame(RoundingSkip::StandingPrice, $rounding?->skipped);
    }

    /** @return iterable<string, array{0: RoundingMode, 1: ?float}> */
    public static function inactive(): iterable
    {
        yield 'off' => [RoundingMode::Off, 0.5];
        yield 'blank step' => [RoundingMode::DiscountPercent, null];
        yield 'zero step' => [RoundingMode::DiscountPercent, 0.0];
        yield 'the other mode' => [RoundingMode::QuoteTotal, 10.0];
    }

    #[DataProvider('inactive')]
    public function testNothingRoundsUnlessThisModeHasAStep(RoundingMode $mode, ?float $step): void
    {
        $offer = self::offer(7.34);

        self::assertSame([$offer, null], DiscountRounding::offer(self::limits($mode, $step), $offer, null, 0.0));
    }

    public function testAPerLineOfferIsNotRounded(): void
    {
        $offer = new ProposedOffer(orderTotalNet: 1000.0, price: new OfferedPrice(linePricesNet: [
            new QuoteLinePrice('line-1', 92.66),
        ]));

        self::assertSame([$offer, null], DiscountRounding::offer(self::limits(), $offer, null, 0.0));
    }
}
