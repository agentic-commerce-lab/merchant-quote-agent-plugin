<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Mandate;

use MerchantQuoteAgentPlugin\Policy\Data\BundlePolicy;
use MerchantQuoteAgentPlugin\Policy\Data\DeliveryPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\VolumeTier;
use MerchantQuoteAgentPlugin\Protocol\Mandate\NegotiationBands;
use PHPUnit\Framework\TestCase;

/**
 * Direct coverage of the delivery and bundle blocks (DeliveryBand,
 * BundleBand), which SellerMandateFactoryTest never exercised, plus the
 * null-counterOfferMaxPercent fallback — a buyer relies on these field names
 * and on absent-vs-null being exactly right, since absent-vs-null changes the
 * signed bytes.
 */
final class NegotiationBandsTest extends TestCase
{
    public function testDeliveryBandPublishesEveryConfiguredField(): void
    {
        $bands = NegotiationBands::fromPolicy(new NegotiationPolicy(
            price: new QuoteLimits(maxDiscountPercent: 15.0),
            delivery: new DeliveryPolicy(
                freeShippingAboveNet: 500.0,
                maxShippingWaiverNet: 50.0,
                expeditedAllowed: true,
                committedLeadTimeDaysMin: 3,
            ),
        ));

        self::assertSame(
            [
                'freeShippingAboveNet' => 500.0,
                'maxShippingWaiverNet' => 50.0,
                'expeditedAllowed' => true,
                'committedLeadTimeDaysMin' => 3,
            ],
            $bands['delivery'],
        );
    }

    public function testBundleBandPublishesVolumeTiersWithDiscountConvertedToBps(): void
    {
        $bands = NegotiationBands::fromPolicy(new NegotiationPolicy(
            price: new QuoteLimits(maxDiscountPercent: 15.0),
            bundle: new BundlePolicy(volumeTiers: [
                new VolumeTier(minQty: 10, discountPercent: 5.0),
                new VolumeTier(minQty: 50, discountPercent: 12.5),
            ]),
        ));

        self::assertSame(
            [
                'volumeTiers' => [
                    ['minQty' => 10, 'discountBps' => 500],
                    ['minQty' => 50, 'discountBps' => 1250],
                ],
            ],
            $bands['bundle'],
        );
    }

    public function testANullCounterOfferMaxPercentFallsBackAndOmitsCounterUpToBps(): void
    {
        $bands = NegotiationBands::fromPolicy(
            new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 15.0, counterOfferMaxPercent: null)),
        );

        self::assertSame($bands['autoGrantMaxBps'], $bands['counterAtBps']);
        self::assertArrayNotHasKey('counterUpToBps', $bands);
    }
}
