<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\AuthorityBrief;
use MerchantQuoteAgentPlugin\Policy\Data\BundlePolicy;
use MerchantQuoteAgentPlugin\Policy\Data\DeliveryPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentTerm;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\VolumeTier;
use PHPUnit\Framework\TestCase;

/**
 * The negotiate prompt has to state every dimension the response schema
 * allows. It used to state only the discount cap, and a live model answered
 * "I don't have approval to change payment terms" on a shop where net 30 was
 * configured — so an allowed concession could never be offered.
 */
final class AuthorityBriefTest extends TestCase
{
    private static function policy(
        ?DeliveryPolicy $delivery = null,
        ?PaymentPolicy $payment = null,
        ?BundlePolicy $bundle = null,
    ): NegotiationPolicy {
        return new NegotiationPolicy(
            price: new QuoteLimits(maxDiscountPercent: 10.0),
            delivery: $delivery,
            payment: $payment,
            bundle: $bundle,
        );
    }

    public function testTheDiscountCapIsAlwaysStated(): void
    {
        self::assertStringContainsString('- maximum discount you may grant: 10.00%', AuthorityBrief::of(
            self::policy(),
            null,
        ));
    }

    /** The regression: an allowed term must reach the prompt, by name. */
    public function testAllowedPaymentTermsAreNamed(): void
    {
        $brief = AuthorityBrief::of(
            self::policy(payment: new PaymentPolicy(
                allowedTerms: [PaymentTerm::Prepaid, PaymentTerm::Net30],
                maxNetDays: 30,
                minDepositPercent: 15.0,
            )),
            null,
        );

        self::assertStringContainsString('- payment terms you may grant: prepaid, net_30', $brief);
        self::assertStringContainsString('- the most net days you may grant is 30', $brief);
        self::assertStringContainsString('- any deposit you ask for must be at least 15.00%', $brief);
    }

    public function testDeliveryLimitsReachThePrompt(): void
    {
        $brief = AuthorityBrief::of(
            self::policy(delivery: new DeliveryPolicy(
                freeShippingAboveNet: 500.0,
                maxShippingWaiverNet: 49.5,
                expeditedAllowed: true,
                committedLeadTimeDaysMin: 3,
            )),
            null,
        );

        self::assertStringContainsString('- you may offer expedited shipping', $brief);
        self::assertStringContainsString('- you may waive shipping on orders above 500.00 net', $brief);
        self::assertStringContainsString('- the most shipping cost you may waive is 49.50 net', $brief);
        self::assertStringContainsString('- the shortest lead time you may commit to is 3 days', $brief);
    }

    /**
     * A concession the merchant never enabled must be stated as forbidden, not
     * left out. Silence is what made the model escalate rather than counter.
     */
    public function testAnUnsetDimensionIsAnExplicitRefusal(): void
    {
        $brief = AuthorityBrief::of(self::policy(), null);

        self::assertStringContainsString('you may NOT offer free shipping, expedited shipping', $brief);
        self::assertStringContainsString('you may NOT offer any payment term', $brief);
    }

    /** An empty allowlist is a refusal too, not "any term goes". */
    public function testAnEmptyTermListIsARefusal(): void
    {
        $brief = AuthorityBrief::of(self::policy(payment: new PaymentPolicy(allowedTerms: [])), null);

        self::assertStringContainsString('you may NOT offer any payment term', $brief);
        self::assertStringNotContainsString('payment terms you may grant', $brief);
    }

    public function testExpeditedOffByDefaultIsStatedAsForbidden(): void
    {
        $brief = AuthorityBrief::of(self::policy(delivery: new DeliveryPolicy(expeditedAllowed: false)), null);

        self::assertStringContainsString('- you may NOT offer expedited shipping', $brief);
        self::assertStringNotContainsString('- you may offer expedited shipping', $brief);
    }

    public function testACounteredAskIsCalledOutAndVolumeTiersArePublished(): void
    {
        $brief = AuthorityBrief::of(self::policy(bundle: new BundlePolicy(volumeTiers: [
            new VolumeTier(minQty: 10, discountPercent: 3.0),
            new VolumeTier(minQty: 50, discountPercent: 6.5),
        ])), counteredRequestPercent: 25.0);

        self::assertStringContainsString('the buyer asked for 25.00%, which is above your cap', $brief);
        self::assertStringContainsString(
            '- volume pricing the merchant publishes: 10+ units → 3.00%, 50+ units → 6.50%',
            $brief,
        );
    }
}
