<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\Band;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteDecision;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteDecisionKind;

/**
 * Port of `priceBand` in src/policy/negotiate-decision.ts.
 */
final class PriceBandClassifier
{
    public function classify(QuoteDecision $decision): Band
    {
        if ($decision->kind === QuoteDecisionKind::Escalate) {
            return Band::Escalate;
        }

        return $decision->autoReply?->counteredRequestPercent !== null ? Band::Counter : Band::Grant;
    }
}
