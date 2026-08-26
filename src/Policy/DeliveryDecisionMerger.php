<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\Band;
use MerchantQuoteAgentPlugin\Policy\Data\DeliveryDecision;

/**
 * Merge independent free-shipping/expedited/lead-time outcomes into one
 * DeliveryDecision. Ported from `mergeOutcomes` in
 * src/policy/negotiate-dimensions.ts.
 */
final class DeliveryDecisionMerger
{
    /** @param list<DeliveryDecision> $outcomes */
    public static function merge(array $outcomes, BandAggregator $bandAggregator): DeliveryDecision
    {
        $reasons = [];
        $freeShippingGranted = null;
        $expeditedGranted = null;
        $committedLeadTimeDays = null;

        // Last-non-null wins per field, matching TS's Object.assign(grant,
        // outcome.grant) — each check only ever sets its own field today, so
        // this never actually collides, but the merge order should match the
        // source exactly rather than rely on that staying true.
        foreach ($outcomes as $outcome) {
            $freeShippingGranted = $outcome->freeShippingGranted ?? $freeShippingGranted;
            $expeditedGranted = $outcome->expeditedGranted ?? $expeditedGranted;
            $committedLeadTimeDays = $outcome->committedLeadTimeDays ?? $committedLeadTimeDays;
            if ($outcome->reason !== null) {
                $reasons[] = $outcome->reason;
            }
        }

        return new DeliveryDecision(
            band: $bandAggregator->aggregate(array_map(static fn(DeliveryDecision $o): Band => $o->band, $outcomes)),
            freeShippingGranted: $freeShippingGranted,
            expeditedGranted: $expeditedGranted,
            committedLeadTimeDays: $committedLeadTimeDays,
            reason: $reasons === [] ? null : implode('; ', $reasons),
        );
    }
}
