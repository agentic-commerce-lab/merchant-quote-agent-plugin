<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\Band;
use MerchantQuoteAgentPlugin\Policy\Data\BundleDecision;
use MerchantQuoteAgentPlugin\Policy\Data\BundlePolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Policy\Data\VolumeTier;

/**
 * Port of `decideBundle` in src/policy/negotiate-dimensions.ts.
 */
final class BundleDecider
{
    public function decide(QuoteSnapshot $snapshot, ?BundlePolicy $policy, QuoteLimits $priceLimits): BundleDecision
    {
        if ($policy === null) {
            return new BundleDecision(band: Band::Escalate, reason: 'no bundle policy configured');
        }

        $tier = $this->highestQualifyingTier($policy->volumeTiers, $this->qualifyingQty($snapshot));
        if ($tier === null) {
            return new BundleDecision(band: Band::Grant, reason: 'no volume tier reached');
        }

        // Ceiling composition: the tier never authorizes more than the price
        // band's max discount, so bundle + price cannot stack past policy.
        return new BundleDecision(
            band: Band::Grant,
            grantedDiscountPercent: min($tier->discountPercent, $priceLimits->maxDiscountPercent),
            appliedTier: $tier,
        );
    }

    private function qualifyingQty(QuoteSnapshot $snapshot): int
    {
        $max = 0;
        foreach ($snapshot->lines as $line) {
            $max = max($max, $line->quantity);
        }

        return $max;
    }

    /** @param list<VolumeTier> $tiers */
    private function highestQualifyingTier(array $tiers, int $qty): ?VolumeTier
    {
        $best = null;
        foreach ($tiers as $tier) {
            if ($qty < $tier->minQty) {
                continue;
            }
            if ($best === null || $tier->minQty > $best->minQty) {
                $best = $tier;
            }
        }

        return $best;
    }
}
