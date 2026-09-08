<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\AuthorityBrief;
use MerchantQuoteAgentPlugin\Policy\Data\BundlePolicy;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\VolumeTier;
use PHPUnit\Framework\TestCase;

/**
 * What the negotiate prompt tells the model it may do.
 *
 * The delivery and payment cases are gone along with those dimensions: AskGate
 * hands every non-price ask to a human, so the brief has no term left to
 * permit. What remains is the discount cap — and the things that must NOT
 * appear beside it, a volume tier being the one the model read as a floor to
 * volunteer.
 */
final class AuthorityBriefTest extends TestCase
{
    private static function policy(?BundlePolicy $bundle = null): NegotiationPolicy
    {
        return new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 10.0), bundle: $bundle);
    }

    public function testTheDiscountCapIsAlwaysStated(): void
    {
        self::assertStringContainsString('- maximum discount you may grant: 10.00%', AuthorityBrief::of(
            self::policy(),
            null,
        ));
    }

    public function testACounteredAskIsCalledOut(): void
    {
        $brief = AuthorityBrief::of(self::policy(), counteredRequestPercent: 25.0);

        self::assertStringContainsString('the buyer asked for 25.00%, which is above your cap', $brief);
    }

    public function testNoAskAboveTheCapMeansNoCounterInstruction(): void
    {
        self::assertStringNotContainsString('above your cap', AuthorityBrief::of(self::policy(), null));
    }

    public function testVolumeTiersAreNotSuggestedToTheModel(): void
    {
        // The tier used to be published here as guidance and the model anchored
        // on it: quote 1017 answered a 2.70% ask with 5% and said so outright —
        // "In line with our volume tier for purchasing 10 or more units per
        // item". A tier is what the merchant honours when ASKED, never a floor
        // to volunteer, so it belongs in the signed mandate and not in the
        // authority the model negotiates against.
        $brief = AuthorityBrief::of(self::policy(bundle: new BundlePolicy(volumeTiers: [
            new VolumeTier(minQty: 10, discountPercent: 3.0),
            new VolumeTier(minQty: 50, discountPercent: 6.5),
        ])), null);

        self::assertStringNotContainsString('volume', $brief);
        self::assertStringNotContainsString('3.00%', $brief);
    }

    public function testTheBriefSaysNothingAboutDeliveryOrPayment(): void
    {
        // A brief that still mentioned them would invite the model to offer a
        // term AskGate escalates and QuoteUpdate cannot write.
        $brief = AuthorityBrief::of(self::policy(), null);

        self::assertStringNotContainsString('shipping', $brief);
        self::assertStringNotContainsString('payment', $brief);
    }
}
