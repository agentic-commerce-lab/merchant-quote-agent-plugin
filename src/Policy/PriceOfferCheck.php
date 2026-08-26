<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;

/**
 * Port of `checkPrice` in src/policy/negotiate-authorize.ts.
 */
final class PriceOfferCheck
{
    private const float EPSILON = 1e-6;

    /** @return list<string> */
    public function check(OfferedPrice $offer, NegotiationPolicy $policy): array
    {
        if (
            $offer->discountPercent !== null
            && $offer->discountPercent > ($policy->price->maxDiscountPercent + self::EPSILON)
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
