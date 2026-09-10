<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteDecision;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteDecisionKind;

/**
 * Port of `priceEscalationReasons` in the retired TS agent (policy/negotiate-decision.ts).
 */
final class PriceEscalationReasons
{
    /** @return list<string> */
    public function reasons(QuoteDecision $decision): array
    {
        if ($decision->kind !== QuoteDecisionKind::Escalate || $decision->escalation === null) {
            return [];
        }

        return [sprintf('price: %s', $decision->escalation->reason->value)];
    }
}
