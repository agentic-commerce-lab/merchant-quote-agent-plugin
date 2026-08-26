<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\Band;
use MerchantQuoteAgentPlugin\Policy\Data\DeliveryAsk;
use MerchantQuoteAgentPlugin\Policy\Data\DeliveryDecision;
use MerchantQuoteAgentPlugin\Policy\Data\DeliveryPolicy;

/**
 * Port of `decideDelivery` in src/policy/negotiate-dimensions.ts.
 */
final class DeliveryDecider
{
    private readonly BandAggregator $bandAggregator;

    public function __construct(?BandAggregator $bandAggregator = null)
    {
        $this->bandAggregator = $bandAggregator ?? new BandAggregator();
    }

    public function decide(DeliveryAsk $ask, ?DeliveryPolicy $policy, float $orderTotalNet): DeliveryDecision
    {
        if ($policy === null) {
            return new DeliveryDecision(band: Band::Escalate, reason: 'no delivery policy configured');
        }

        $outcomes = array_values(array_filter([
            $ask->freeShipping ? FreeShippingDecider::decide($ask, $policy, $orderTotalNet) : null,
            $ask->expedited ? ExpeditedDecider::decide($policy) : null,
            $ask->requestedLeadTimeDays !== null ? LeadTimeDecider::decide($ask->requestedLeadTimeDays, $policy) : null,
        ]));

        return DeliveryDecisionMerger::merge($outcomes, $this->bandAggregator);
    }
}
