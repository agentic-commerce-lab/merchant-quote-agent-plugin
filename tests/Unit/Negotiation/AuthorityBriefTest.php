<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\AuthorityBrief;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use PHPUnit\Framework\TestCase;

/**
 * What the negotiate prompt tells the model it may do.
 *
 * The delivery, payment and volume-tier cases are gone along with those
 * dimensions: AskGate hands every non-price ask to a human, so the brief has no
 * term left to permit. What remains is the discount cap — and the things that
 * must NOT appear beside it.
 */
final class AuthorityBriefTest extends TestCase
{
    private static function policy(): NegotiationPolicy
    {
        return new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 10.0));
    }

    public function testTheDiscountCapIsAlwaysStated(): void
    {
        self::assertStringContainsString('- maximum discount you may grant: 10.00%', AuthorityBrief::of(
            self::policy(),
            null,
        ));
    }

    public function testTheCapIsShownRoundedDownSoTheShownFigureIsAlwaysGrantable(): void
    {
        // PM testing: a 7.2488% ceiling was shown as "7.25%", the model offered
        // exactly that, and PriceOfferCheck (full precision) rejected it --
        // eleven proposal_rejected escalations. The shown cap must never exceed
        // the true one.
        $brief = AuthorityBrief::of(new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 7.2488279)), null);

        self::assertStringContainsString('- maximum discount you may grant: 7.24%', $brief);
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
        // item". The tier configuration is gone entirely now; this asserts the
        // word cannot come back into the brief with it.
        self::assertStringNotContainsString('volume', AuthorityBrief::of(self::policy(), null));
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
