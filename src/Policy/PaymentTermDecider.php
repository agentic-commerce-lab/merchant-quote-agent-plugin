<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\Band;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentDecision;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentTerm;

/**
 * Port of `decideTerm` in src/policy/negotiate-dimensions.ts.
 */
final class PaymentTermDecider
{
    public static function decide(PaymentTerm $term, PaymentPolicy $policy): PaymentDecision
    {
        return in_array($term, $policy->allowedTerms, strict: true)
            ? new PaymentDecision(band: Band::Grant, grantedTerm: $term)
            : new PaymentDecision(band: Band::Escalate, reason: sprintf(
                'payment term %s not in the allowed set',
                $term->value,
            ));
    }
}
