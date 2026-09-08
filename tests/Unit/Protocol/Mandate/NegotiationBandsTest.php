<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Mandate;

use MerchantQuoteAgentPlugin\Policy\Data\BundlePolicy;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\VolumeTier;
use MerchantQuoteAgentPlugin\Protocol\Mandate\NegotiationBands;
use PHPUnit\Framework\TestCase;

/**
 * Direct coverage of the bundle block (BundleBand), which
 * SellerMandateFactoryTest never exercised, plus the null-counterOfferMaxPercent
 * fallback — a buyer relies on these field names, and absent-vs-null has to be
 * exactly right because it changes the signed bytes.
 */
final class NegotiationBandsTest extends TestCase
{
    public function testNoDeliveryOrPaymentBandIsPublished(): void
    {
        // Both bands were removed with their policies. The mandate is signed
        // and read by counterparty agents, so advertising authority the agent
        // never exercises -- AskGate escalates every non-price ask -- is a
        // claim the shop cannot honour.
        $bands = NegotiationBands::fromPolicy(new NegotiationPolicy(
            price: new QuoteLimits(maxDiscountPercent: 10.0, counterOfferMaxPercent: 20.0),
            bundle: new BundlePolicy(volumeTiers: [new VolumeTier(minQty: 10, discountPercent: 5.0)]),
        ));

        self::assertArrayNotHasKey('delivery', $bands);
        self::assertArrayNotHasKey('payment', $bands);
        self::assertArrayHasKey('bundle', $bands);
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
