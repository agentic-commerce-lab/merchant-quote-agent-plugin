<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\DeliveryOfferLimits;
use MerchantQuoteAgentPlugin\Policy\Data\DeliveryPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\OfferLimits;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentOfferLimits;

/**
 * The bands the agent is allowed to operate within — what it's told before
 * it proposes an offer. Same numbers the offer checks enforce afterwards.
 *
 * Ported from `buildLimits`/`offerLimits` in
 * src/policy/negotiate-authorize.ts.
 */
final class OfferLimitsBuilder
{
    public function build(NegotiationPolicy $policy): OfferLimits
    {
        $delivery = $policy->delivery;
        $payment = $policy->payment;

        return new OfferLimits(
            maxDiscountPercent: $policy->price->maxDiscountPercent,
            payment: new PaymentOfferLimits(
                allowedTerms: $payment->allowedTerms ?? [],
                maxNetDays: $payment->maxNetDays ?? null,
                minDepositPercent: $payment->minDepositPercent ?? null,
            ),
            delivery: new DeliveryOfferLimits(
                freeShippingAllowed: $this->freeShippingConfigured($delivery),
                expeditedAllowed: $delivery->expeditedAllowed ?? false,
                committedLeadTimeDaysMin: $delivery->committedLeadTimeDaysMin ?? null,
            ),
        );
    }

    private function freeShippingConfigured(?DeliveryPolicy $delivery): bool
    {
        return (
            $delivery !== null
            && ($delivery->freeShippingAboveNet !== null || $delivery->maxShippingWaiverNet !== null)
        );
    }
}
