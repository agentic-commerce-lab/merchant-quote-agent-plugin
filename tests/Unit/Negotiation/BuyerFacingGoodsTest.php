<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\BuyerFacingGoods;
use MerchantQuoteAgentPlugin\Negotiation\OfferWrite;
use MerchantQuoteAgentPlugin\Negotiation\QuoteTotalRounding;
use MerchantQuoteAgentPlugin\Negotiation\SnapshotAdapter;
use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\RoundingMode;
use MerchantQuoteAgentPlugin\Policy\Data\RoundingSkip;
use PHPUnit\Framework\TestCase;

final class BuyerFacingGoodsTest extends TestCase
{
    public function testAGrossQuotesGoodsAndShippingAreReadBackInItsOwnTaxSpace(): void
    {
        $goods = BuyerFacingGoods::of(RoundingFixture::mixedGross());

        self::assertEqualsWithDelta(1457.5, $goods->gross, 1e-9);
        self::assertEqualsWithDelta(5.95, $goods->otherCosts, 1e-9);
        self::assertFalse($goods->taxOnTop);
    }

    public function testAHeldDiscountIsNeitherGoodsNorOtherCosts(): void
    {
        $goods = BuyerFacingGoods::of(RoundingFixture::mixedGrossHolding());

        self::assertEqualsWithDelta(1457.5, $goods->gross, 1e-9);
        self::assertEqualsWithDelta(5.95, $goods->otherCosts, 1e-9);
    }

    public function testTaxAddedOnTopOfNetLinesIsRecognised(): void
    {
        self::assertTrue(BuyerFacingGoods::of(RoundingFixture::netQuote(1493.45))->taxOnTop);
        self::assertFalse(BuyerFacingGoods::of(RoundingFixture::netQuote(1255.0))->taxOnTop);
    }

    public function testAnAbsoluteAmountIsSpreadOverTheGoodsByValue(): void
    {
        self::assertEqualsWithDelta(
            0.929022,
            BuyerFacingGoods::of(RoundingFixture::mixedGross())->factorAfter(103.45),
            1e-6,
        );
    }

    /**
     * QuoteTotalRounding keeps factorAfter() inside (0, 1]. 100% off with a
     * step of the shipping cost: 5.95 → 5.95, so the amount (1457.50) takes
     * the whole of the goods and would leave PredictedWrite a factor of 0.
     */
    public function testAnAmountTakingTheWholeOfTheGoodsIsNeverWritten(): void
    {
        $quote = RoundingFixture::mixedGross();
        $write = OfferWrite::of(
            new ProposedOffer(orderTotalNet: 1255.0, price: new OfferedPrice(discountPercent: 100.0)),
            null,
            $quote,
            100.0,
        );

        [$written, $rounding] = QuoteTotalRounding::of(
            $write,
            $quote,
            SnapshotAdapter::toPolicy($quote),
            RoundingFixture::settings(RoundingMode::QuoteTotal, 5.95)->policy->price,
            false,
        );

        self::assertSame($write, $written);
        self::assertSame(RoundingSkip::ToZero, $rounding?->skipped);
    }
}
