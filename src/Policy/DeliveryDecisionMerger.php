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

        foreach ($outcomes as $outcome) {
            $freeShippingGranted ??= $outcome->freeShippingGranted;
            $expeditedGranted ??= $outcome->expeditedGranted;
            $committedLeadTimeDays ??= $outcome->committedLeadTimeDays;
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
