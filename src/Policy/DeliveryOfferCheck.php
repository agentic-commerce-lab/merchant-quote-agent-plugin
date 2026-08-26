<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;

/**
 * Port of `checkDelivery` in src/policy/negotiate-authorize.ts.
 */
final class DeliveryOfferCheck
{
    public function __construct(
        private readonly FreeShipOfferCheck $freeShip = new FreeShipOfferCheck(),
        private readonly ExpeditedOfferCheck $expedited = new ExpeditedOfferCheck(),
        private readonly LeadTimeOfferCheck $leadTime = new LeadTimeOfferCheck(),
    ) {}

    /** @return list<string> */
    public function check(ProposedOffer $offer, NegotiationPolicy $policy): array
    {
        return array_values(array_filter(
            [
                $this->freeShip->check($offer->delivery, $offer->orderTotalNet, $policy->delivery),
                $this->expedited->check($offer->delivery, $policy->delivery),
                $this->leadTime->check($offer->delivery, $policy->delivery),
            ],
            static fn(?string $v): bool => $v !== null,
        ));
    }
}
