<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\NonPriceDecision;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;

/**
 * Independent re-verification of the non-price negotiation terms against the
 * shop policy — the deterministic gate for delivery / payment / bundle,
 * mirroring OfferVerifier's role for price. Pure: it re-derives every
 * granted term from the policy and reports any that a bug (or a future
 * LLM-in-the-loop) let through.
 *
 * Ported from `verifyNegotiationDecision` in src/policy/negotiate-verify.ts.
 */
final class NegotiationVerifier
{
    public function __construct(
        private readonly DeliveryGrantVerifier $delivery = new DeliveryGrantVerifier(),
        private readonly PaymentGrantVerifier $payment = new PaymentGrantVerifier(),
        private readonly BundleGrantVerifier $bundle = new BundleGrantVerifier(),
    ) {}

    /** @return list<string> */
    public function verify(NonPriceDecision $decision, NegotiationPolicy $policy, QuoteSnapshot $snapshot): array
    {
        return [
            ...$this->delivery->verify($decision->delivery, $policy->delivery, $snapshot),
            ...$this->payment->verify($decision->payment, $policy->payment),
            ...$this->bundle->verify($decision->bundle, $policy),
        ];
    }
}
