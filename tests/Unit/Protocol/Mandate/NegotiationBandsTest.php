<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Mandate;

use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Protocol\Mandate\NegotiationBands;
use PHPUnit\Framework\TestCase;

/**
 * What the signed mandate publishes as authority, and what it must not: a
 * buyer relies on these field names, and absent-vs-null has to be exactly
 * right because it changes the signed bytes.
 */
final class NegotiationBandsTest extends TestCase
{
    public function testOnlyPriceBandsArePublished(): void
    {
        // Delivery, payment and the volume tiers were each published here
        // until their policies went. The mandate is signed and read by
        // counterparty agents, so advertising authority the agent never
        // exercises -- AskGate escalates every non-price ask -- is a claim the
        // shop cannot honour.
        $bands = NegotiationBands::fromPolicy(
            new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 10.0, counterOfferMaxPercent: 20.0)),
        );

        self::assertSame(['autoGrantMaxBps', 'counterAtBps', 'escalateAboveBps', 'counterUpToBps'], array_keys($bands));
    }

    public function testANullCounterOfferMaxPercentFallsBackAndOmitsCounterUpToBps(): void
    {
        $bands = NegotiationBands::fromPolicy(
            new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 15.0, counterOfferMaxPercent: null)),
        );

        self::assertSame($bands['autoGrantMaxBps'], $bands['counterAtBps']);
        self::assertArrayNotHasKey('counterUpToBps', $bands);
    }

    /**
     * In Draft Mode nothing is granted without a human, so the signed mandate
     * must not say the agent grants anything itself. Everything above zero
     * escalates, which is exactly what a draft is.
     */
    public function testDraftModeClaimsNoAutoGrant(): void
    {
        $bands = NegotiationBands::fromPolicy(
            new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 10.0, counterOfferMaxPercent: 20.0)),
            draftMode: true,
        );

        self::assertSame(0, $bands['autoGrantMaxBps']);
        self::assertSame(0, $bands['escalateAboveBps']);
    }
}
