<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;

/**
 * Port of `checkPrice` in the retired TS agent (policy/negotiate-authorize.ts).
 */
final class PriceOfferCheck
{
    /** @return list<string> */
    public function check(OfferedPrice $offer, NegotiationPolicy $policy): array
    {
        if (
            $offer->discountPercent !== null
            && $offer->discountPercent > ($policy->price->maxDiscountPercent + Epsilon::RATE)
        ) {
            return [sprintf(
                'discount %s%% exceeds the %s%% limit',
                $offer->discountPercent,
                $policy->price->maxDiscountPercent,
            )];
        }

        return [];
    }
}
