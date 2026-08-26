<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\PaymentDecision;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentPolicy;

/**
 * Port of `checkNetDays` in src/policy/negotiate-verify.ts.
 */
final class GrantedNetDaysCheck
{
    public function check(PaymentDecision $decision, PaymentPolicy $policy): ?string
    {
        if ($decision->grantedNetDays === null || $policy->maxNetDays === null) {
            return null;
        }

        return $decision->grantedNetDays > ($policy->maxNetDays + Epsilon::RATE)
            ? sprintf('granted net days %d exceeds the %d ceiling', $decision->grantedNetDays, $policy->maxNetDays)
            : null;
    }
}
