<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\NegotiationDecision;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationProposal;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;

/**
 * Deterministic multi-dimension negotiation. Delegates the price dimension
 * to QuoteDecider (no band logic is duplicated) and the delivery/payment/
 * bundle dimensions to NonPriceTermsDecider. Every value is computed by
 * code, never by an LLM.
 *
 * Ported from `decideNegotiation` in src/policy/negotiate-decision.ts.
 */
final class NegotiationDecider
{
    public function __construct(
        private readonly QuoteDecider $quoteDecider = new QuoteDecider(),
        private readonly NonPriceTermsDecider $nonPriceTermsDecider = new NonPriceTermsDecider(),
        private readonly PriceBandClassifier $priceBandClassifier = new PriceBandClassifier(),
        private readonly PriceEscalationReasons $priceEscalationReasons = new PriceEscalationReasons(),
        private readonly BandAggregator $bandAggregator = new BandAggregator(),
    ) {}

    public function decide(
        QuoteSnapshot $snapshot,
        NegotiationPolicy $policy,
        ?NegotiationProposal $proposal = null,
    ): NegotiationDecision {
        $price = $this->quoteDecider->decide($snapshot, $policy->price, $proposal?->price);
        $nonPrice = $this->nonPriceTermsDecider->decide($snapshot, $policy, $proposal?->nonPrice);

        return new NegotiationDecision(
            overall: $this->bandAggregator->aggregate([$this->priceBandClassifier->classify($price), $nonPrice->band]),
            price: $price,
            nonPrice: $nonPrice,
            escalationReasons: [...$this->priceEscalationReasons->reasons($price), ...$nonPrice->escalationReasons],
        );
    }
}
