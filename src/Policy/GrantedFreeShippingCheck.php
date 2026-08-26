<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\DeliveryDecision;
use MerchantQuoteAgentPlugin\Policy\Data\DeliveryPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;

/**
 * Port of `checkFreeShipping` in src/policy/negotiate-verify.ts.
 */
final class GrantedFreeShippingCheck
{
    public function check(DeliveryDecision $decision, DeliveryPolicy $policy, QuoteSnapshot $snapshot): ?string
    {
        if (!($decision->freeShippingGranted ?? false)) {
            return null;
        }

        $byOrderSize =
            $policy->freeShippingAboveNet !== null
            && ($snapshot->totalNet + Epsilon::RATE) >= $policy->freeShippingAboveNet;
        // The waiver-cap path depends on the shipping cost (not on the
        // decision), so it can only be re-verified as far as the policy
        // permitting it at all.
        $byWaiverCap = $policy->maxShippingWaiverNet !== null;

        return $byOrderSize || $byWaiverCap ? null : 'free shipping granted but policy permits neither path';
    }
}
