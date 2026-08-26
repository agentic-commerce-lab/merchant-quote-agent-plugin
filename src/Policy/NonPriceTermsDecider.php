<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\NegotiationAsks;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\NonPriceDecision;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;

/**
 * The delivery/payment/bundle dimensions only — the async servicing path
 * decides price through its own QuoteDecider/re-pricing flow and composes
 * this on top.
 *
 * Ported from `decideNonPriceTerms` in src/policy/negotiate-decision.ts.
 */
final class NonPriceTermsDecider
{
    public function __construct(
        private readonly DeliveryDecider $delivery = new DeliveryDecider(),
        private readonly PaymentDecider $payment = new PaymentDecider(),
        private readonly BundleDecider $bundle = new BundleDecider(),
        private readonly BandAggregator $bandAggregator = new BandAggregator(),
        private readonly DimensionReason $dimensionReason = new DimensionReason(),
    ) {}

    public function decide(QuoteSnapshot $snapshot, NegotiationPolicy $policy, ?NegotiationAsks $asks): NonPriceDecision
    {
        $delivery = $asks?->delivery !== null
            ? $this->delivery->decide($asks->delivery, $policy->delivery, $snapshot->totalNet)
            : null;
        $payment = $asks?->payment !== null ? $this->payment->decide($asks->payment, $policy->payment) : null;
        $bundle = $asks->bundle->requested ?? false
            ? $this->bundle->decide($snapshot, $policy->bundle, $policy->price)
            : null;

        $bands = array_values(array_filter([$delivery?->band, $payment?->band, $bundle?->band]));
        $reasons = [
            ...$this->dimensionReason->reason('delivery', $delivery?->band, $delivery?->reason),
            ...$this->dimensionReason->reason('payment', $payment?->band, $payment?->reason),
            ...$this->dimensionReason->reason('bundle', $bundle?->band, $bundle?->reason),
        ];

        return new NonPriceDecision(
            band: $this->bandAggregator->aggregate($bands),
            delivery: $delivery,
            payment: $payment,
            bundle: $bundle,
            escalationReasons: $reasons,
        );
    }
}
