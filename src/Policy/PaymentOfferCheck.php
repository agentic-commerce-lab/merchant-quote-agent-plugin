<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;

/**
 * Port of `checkPayment` in src/policy/negotiate-authorize.ts.
 */
final class PaymentOfferCheck
{
    public function __construct(
        private readonly PaymentTermOfferCheck $term = new PaymentTermOfferCheck(),
        private readonly NetDaysOfferCheck $netDays = new NetDaysOfferCheck(),
        private readonly DepositOfferCheck $deposit = new DepositOfferCheck(),
    ) {}

    /** @return list<string> */
    public function check(ProposedOffer $offer, NegotiationPolicy $policy): array
    {
        return array_values(array_filter([
            $this->term->check($offer->payment, $policy->payment),
            $this->netDays->check($offer->payment, $policy->payment),
            $this->deposit->check($offer->payment, $policy->payment),
        ]));
    }
}
