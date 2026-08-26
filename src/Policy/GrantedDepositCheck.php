<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\PaymentDecision;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentPolicy;

/**
 * Port of `checkDeposit` in src/policy/negotiate-verify.ts.
 */
final class GrantedDepositCheck
{
    public function check(PaymentDecision $decision, PaymentPolicy $policy): ?string
    {
        if ($decision->grantedDepositPercent === null || $policy->minDepositPercent === null) {
            return null;
        }

        return ($decision->grantedDepositPercent + Epsilon::RATE) < $policy->minDepositPercent
            ? sprintf(
                'granted deposit %s%% is below the %s%% minimum',
                $decision->grantedDepositPercent,
                $policy->minDepositPercent,
            )
            : null;
    }
}
