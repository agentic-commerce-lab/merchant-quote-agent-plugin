<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\Band;
use MerchantQuoteAgentPlugin\Policy\Data\DeliveryDecision;
use MerchantQuoteAgentPlugin\Policy\Data\DeliveryPolicy;

/**
 * Port of `decideLeadTime` in src/policy/negotiate-dimensions.ts.
 */
final class LeadTimeDecider
{
    public static function decide(int $requestedDays, DeliveryPolicy $policy): DeliveryDecision
    {
        if ($policy->committedLeadTimeDaysMin === null) {
            return new DeliveryDecision(band: Band::Escalate, reason: 'no committed lead time configured');
        }

        if (($requestedDays + Epsilon::RATE) >= $policy->committedLeadTimeDaysMin) {
            return new DeliveryDecision(band: Band::Grant, committedLeadTimeDays: $requestedDays);
        }

        return new DeliveryDecision(
            band: Band::Counter,
            committedLeadTimeDays: $policy->committedLeadTimeDaysMin,
            reason: sprintf('lead time countered up to %d days', $policy->committedLeadTimeDaysMin),
        );
    }
}
