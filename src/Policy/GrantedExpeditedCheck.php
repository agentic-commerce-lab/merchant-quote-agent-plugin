<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\DeliveryDecision;
use MerchantQuoteAgentPlugin\Policy\Data\DeliveryPolicy;

/**
 * Port of `checkExpedited` in src/policy/negotiate-verify.ts.
 */
final class GrantedExpeditedCheck
{
    public function check(DeliveryDecision $decision, DeliveryPolicy $policy): ?string
    {
        return ($decision->expeditedGranted ?? false) && !($policy->expeditedAllowed ?? false)
            ? 'expedited shipping granted but not allowed by policy'
            : null;
    }
}
