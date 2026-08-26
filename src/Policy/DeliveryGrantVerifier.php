<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\DeliveryDecision;
use MerchantQuoteAgentPlugin\Policy\Data\DeliveryPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;

/**
 * Port of `verifyDelivery`/`grantsDelivery` in src/policy/negotiate-verify.ts.
 */
final class DeliveryGrantVerifier
{
    public function __construct(
        private readonly GrantedFreeShippingCheck $freeShipping = new GrantedFreeShippingCheck(),
        private readonly GrantedExpeditedCheck $expedited = new GrantedExpeditedCheck(),
        private readonly GrantedLeadTimeCheck $leadTime = new GrantedLeadTimeCheck(),
    ) {}

    /** @return list<string> */
    public function verify(?DeliveryDecision $decision, ?DeliveryPolicy $policy, QuoteSnapshot $snapshot): array
    {
        if ($decision === null) {
            return [];
        }

        if ($policy === null) {
            return $this->grantsAnything($decision) ? ['delivery granted with no delivery policy'] : [];
        }

        return array_values(array_filter([
            $this->freeShipping->check($decision, $policy, $snapshot),
            $this->expedited->check($decision, $policy),
            $this->leadTime->check($decision, $policy),
        ]));
    }

    private function grantsAnything(DeliveryDecision $decision): bool
    {
        return (
            ($decision->freeShippingGranted ?? false)
            || ($decision->expeditedGranted ?? false)
            || $decision->committedLeadTimeDays !== null
        );
    }
}
