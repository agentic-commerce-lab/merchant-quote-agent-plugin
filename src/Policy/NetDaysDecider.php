<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\Band;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentDecision;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentPolicy;

/**
 * Port of `decideNetDays` in src/policy/negotiate-dimensions.ts.
 */
final class NetDaysDecider
{
    public static function decide(int $days, PaymentPolicy $policy): PaymentDecision
    {
        if ($policy->maxNetDays === null) {
            return new PaymentDecision(band: Band::Escalate, reason: 'numeric net-days not configured');
        }

        if ($days <= ($policy->maxNetDays + Epsilon::RATE)) {
            return new PaymentDecision(band: Band::Grant, grantedNetDays: $days);
        }

        return new PaymentDecision(
            band: Band::Counter,
            grantedNetDays: $policy->maxNetDays,
            reason: sprintf('net days countered down to %d', $policy->maxNetDays),
        );
    }
}
