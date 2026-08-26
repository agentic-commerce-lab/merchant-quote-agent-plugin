<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\Band;
use MerchantQuoteAgentPlugin\Policy\Data\DeliveryDecision;
use MerchantQuoteAgentPlugin\Policy\Data\DeliveryPolicy;

/**
 * Port of `decideExpedited` in src/policy/negotiate-dimensions.ts.
 */
final class ExpeditedDecider
{
    public static function decide(DeliveryPolicy $policy): DeliveryDecision
    {
        return $policy->expeditedAllowed ?? false
            ? new DeliveryDecision(band: Band::Grant, expeditedGranted: true)
            : new DeliveryDecision(band: Band::Escalate, reason: 'expedited shipping not allowed');
    }
}
