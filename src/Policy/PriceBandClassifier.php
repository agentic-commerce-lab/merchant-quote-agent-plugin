<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\Band;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteDecision;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteDecisionKind;

/**
 * Port of `priceBand` in the retired TS agent (policy/negotiate-decision.ts).
 */
final class PriceBandClassifier
{
    public function classify(QuoteDecision $decision): Band
    {
        if ($decision->kind === QuoteDecisionKind::Escalate) {
            return Band::Escalate;
        }

        // Band::Counter is unreachable today — no QuoteDecider path sets
        // counteredRequestPercent, matching the TS source exactly: "No auto
        // counter-offer: an ask above the grantable max escalates to a
        // human" (quote-decision.ts). Faithful port, not a porting bug.
        return $decision->autoReply?->counteredRequestPercent !== null ? Band::Counter : Band::Grant;
    }
}
