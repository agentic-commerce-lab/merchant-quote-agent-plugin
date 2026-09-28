<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\OfferWrite;
use MerchantQuoteAgentPlugin\Negotiation\PredictedWrite;
use MerchantQuoteAgentPlugin\Negotiation\SnapshotAdapter;
use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use PHPUnit\Framework\TestCase;

final class PredictedWriteTest extends TestCase
{
    public function testAPercentageWriteMovesEveryGoodByItsPercentage(): void
    {
        $reference = NegotiationFixture::snapshot();
        $offer = new ProposedOffer(orderTotalNet: 1000.0, price: new OfferedPrice(discountPercent: 5.0));

        $predicted = PredictedWrite::of(
            OfferWrite::of($offer, null, $reference, 5.0),
            SnapshotAdapter::toPolicy($reference),
        );

        self::assertEqualsWithDelta(950.0, $predicted->snapshot->totalNet, 1e-9);
        self::assertSame([], $predicted->refusals);
    }

    /**
     * Spec 2026-09-28: the net relief of an absolute discount is its value
     * scaled by the goods' net/gross ratio. SwagCommercial spreads it over the
     * goods by gross value, so every good keeps 1 − 103.45 / 1457.50 of its
     * net price: 1250.00 × 7.098% = 88.72 net off.
     */
    public function testAnAbsoluteWriteTakesItsNetShareOffEveryGood(): void
    {
        $predicted = PredictedWrite::of(
            OfferWrite::absolute(103.45, 1 - (103.45 / 1457.5)),
            SnapshotAdapter::toPolicy(RoundingFixture::mixedGross()),
        );

        self::assertEqualsWithDelta(1166.28, $predicted->snapshot->totalNet, 0.005);
        self::assertEqualsWithDelta(92.902, $predicted->snapshot->lines[0]->unitPriceNet, 0.001);
        self::assertSame([], $predicted->refusals);
    }

    public function testAnAbsoluteWriteBelowAStandingDiscountIsRefused(): void
    {
        // 7.1% of the goods against the 7.2% the buyer already holds.
        $predicted = PredictedWrite::of(
            OfferWrite::absolute(103.45, 1 - (103.45 / 1457.5)),
            SnapshotAdapter::toPolicy(RoundingFixture::mixedGrossHolding()),
        );

        self::assertNotSame([], $predicted->refusals);
    }
}
