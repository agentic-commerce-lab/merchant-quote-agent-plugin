<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\Band;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentDecision;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentPolicy;

/**
 * Port of `decideDeposit` in src/policy/negotiate-dimensions.ts.
 */
final class DepositDecider
{
    private const float EPSILON = 1e-6;

    public static function decide(float $percent, PaymentPolicy $policy): PaymentDecision
    {
        if ($policy->minDepositPercent === null) {
            return new PaymentDecision(band: Band::Escalate, reason: 'deposit terms not configured');
        }

        if (($percent + self::EPSILON) >= $policy->minDepositPercent) {
            return new PaymentDecision(band: Band::Grant, grantedDepositPercent: $percent);
        }

        return new PaymentDecision(
            band: Band::Counter,
            grantedDepositPercent: $policy->minDepositPercent,
            reason: sprintf('deposit countered up to %s%%', $policy->minDepositPercent),
        );
    }
}
