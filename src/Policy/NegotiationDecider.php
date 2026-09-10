<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\NegotiationDecision;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationProposal;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;

/**
 * Deterministic negotiation. Delegates the price dimension to QuoteDecider, so
 * no band logic is duplicated. Every value is computed by code, never by an LLM.
 *
 * There is only the price dimension. Delivery, payment and bundle asks are
 * escalated by AskGate before they reach here, so the non-price decision this
 * used to aggregate was always a granting one -- and BandAggregator is
 * worst-wins, which makes aggregating a Grant a no-op. `overall` is therefore
 * the price band, which is what NegotiationPipeline's gate already assumed.
 *
 * Ported from `decideNegotiation` in the retired TS agent (policy/negotiate-decision.ts).
 */
final class NegotiationDecider
{
    public function __construct(
        private readonly QuoteDecider $quoteDecider = new QuoteDecider(),
        private readonly PriceBandClassifier $priceBandClassifier = new PriceBandClassifier(),
        private readonly PriceEscalationReasons $priceEscalationReasons = new PriceEscalationReasons(),
    ) {}

    public function decide(
        QuoteSnapshot $snapshot,
        NegotiationPolicy $policy,
        ?NegotiationProposal $proposal = null,
    ): NegotiationDecision {
        $price = $this->quoteDecider->decide($snapshot, $policy->price, $proposal?->price);

        return new NegotiationDecision(
            overall: $this->priceBandClassifier->classify($price),
            price: $price,
            escalationReasons: $this->priceEscalationReasons->reasons($price),
        );
    }
}
