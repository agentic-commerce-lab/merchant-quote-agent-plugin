<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\BundleDecision;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;

/**
 * Port of `verifyBundle` in src/policy/negotiate-verify.ts.
 */
final class BundleGrantVerifier
{
    /** @return list<string> */
    public function verify(?BundleDecision $decision, NegotiationPolicy $policy): array
    {
        if ($decision === null || $decision->grantedDiscountPercent === null) {
            return [];
        }

        if ($decision->grantedDiscountPercent > ($policy->price->maxDiscountPercent + Epsilon::RATE)) {
            return [sprintf(
                'bundle discount %s%% exceeds the price ceiling %s%%',
                $decision->grantedDiscountPercent,
                $policy->price->maxDiscountPercent,
            )];
        }

        return [];
    }
}
