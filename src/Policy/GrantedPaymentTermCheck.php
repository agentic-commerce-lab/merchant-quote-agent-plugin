<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\PaymentDecision;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentPolicy;

/**
 * Port of `checkTerm` in src/policy/negotiate-verify.ts.
 */
final class GrantedPaymentTermCheck
{
    public function check(PaymentDecision $decision, PaymentPolicy $policy): ?string
    {
        if ($decision->grantedTerm === null) {
            return null;
        }

        return in_array($decision->grantedTerm, $policy->allowedTerms, strict: true)
            ? null
            : sprintf('granted payment term %s is not in the allowed set', $decision->grantedTerm->value);
    }
}
