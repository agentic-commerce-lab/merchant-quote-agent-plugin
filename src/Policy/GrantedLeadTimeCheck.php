<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\DeliveryDecision;
use MerchantQuoteAgentPlugin\Policy\Data\DeliveryPolicy;

/**
 * Port of `checkLeadTime` in src/policy/negotiate-verify.ts.
 */
final class GrantedLeadTimeCheck
{
    private const float EPSILON = 1e-6;

    public function check(DeliveryDecision $decision, DeliveryPolicy $policy): ?string
    {
        $floor = $policy->committedLeadTimeDaysMin;
        if ($decision->committedLeadTimeDays === null || $floor === null) {
            return null;
        }

        return ($decision->committedLeadTimeDays + self::EPSILON) < $floor
            ? sprintf('committed lead time %dd is faster than the %dd floor', $decision->committedLeadTimeDays, $floor)
            : null;
    }
}
