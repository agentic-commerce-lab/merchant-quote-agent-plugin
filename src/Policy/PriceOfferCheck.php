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
        $discount = $offer->discountPercent;

        if ($discount === null) {
            return [];
        }

        if ($discount > ($policy->price->maxDiscountPercent + Epsilon::RATE)) {
            return [sprintf('discount %s%% exceeds the %s%% limit', $discount, $policy->price->maxDiscountPercent)];
        }

        // #56: the cap bounded the offer from above only. A negative discount
        // is written as a SwagCommercial percentage discount and arrives as a
        // surcharge, and ReplyTemplate::reduction() floors its figure at 0, so
        // the buyer is told the quote came down by 0% while the total rose.
        return (
            $discount < -Epsilon::RATE
                ? [sprintf('discount %s%% is negative; an offer may not raise the quote', $discount)]
                : []
        );
    }
}
