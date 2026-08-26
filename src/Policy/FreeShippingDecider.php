<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\Band;
use MerchantQuoteAgentPlugin\Policy\Data\DeliveryAsk;
use MerchantQuoteAgentPlugin\Policy\Data\DeliveryDecision;
use MerchantQuoteAgentPlugin\Policy\Data\DeliveryPolicy;

/**
 * Port of `decideFreeShipping` in src/policy/negotiate-dimensions.ts.
 */
final class FreeShippingDecider
{
    private const float EPSILON = 1e-6;

    public static function decide(DeliveryAsk $ask, DeliveryPolicy $policy, float $orderTotalNet): DeliveryDecision
    {
        $byOrderSize =
            $policy->freeShippingAboveNet !== null && ($orderTotalNet + self::EPSILON) >= $policy->freeShippingAboveNet;
        $byWaiverCap =
            $policy->maxShippingWaiverNet !== null
            && $ask->shippingCostNet !== null
            && $ask->shippingCostNet <= ($policy->maxShippingWaiverNet + self::EPSILON);

        return $byOrderSize || $byWaiverCap
            ? new DeliveryDecision(band: Band::Grant, freeShippingGranted: true)
            : new DeliveryDecision(band: Band::Escalate, reason: 'free shipping not permitted for this order');
    }
}
