<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\OfferAuthorization;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;

/**
 * Agent-led negotiation authorization. The LLM agent LEADS: it decides what
 * discount / delivery / payment terms to OFFER the customer. This is a pure
 * BOUNDS-CHECK — given the agent's proposed offer, it returns approved (the
 * offer is within the merchant's configured bands) or not-approved with the
 * reason and the caps, so the agent can re-offer within limits or escalate.
 *
 * Ported from `authorizeOffer` in src/policy/negotiate-authorize.ts.
 */
final class OfferAuthorizer
{
    public function __construct(
        private readonly PriceOfferCheck $price = new PriceOfferCheck(),
        private readonly LinePriceOfferCheck $linePrices = new LinePriceOfferCheck(),
        private readonly DeliveryOfferCheck $delivery = new DeliveryOfferCheck(),
        private readonly PaymentOfferCheck $payment = new PaymentOfferCheck(),
        private readonly OfferLimitsBuilder $limitsBuilder = new OfferLimitsBuilder(),
    ) {}

    public function authorize(ProposedOffer $offer, NegotiationPolicy $policy): OfferAuthorization
    {
        $violations = [
            ...$this->price->check($offer->price, $policy),
            ...$this->linePrices->check($offer->price, $policy),
            ...$this->delivery->check($offer, $policy),
            ...$this->payment->check($offer, $policy),
        ];

        return new OfferAuthorization(
            approved: $violations === [],
            violations: $violations,
            limits: $this->limitsBuilder->build($policy),
        );
    }
}
