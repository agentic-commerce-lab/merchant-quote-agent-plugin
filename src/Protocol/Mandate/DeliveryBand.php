<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Mandate;

use MerchantQuoteAgentPlugin\Policy\Data\DeliveryPolicy;

/**
 * The delivery block of `negotiation_bands` — split out of NegotiationBands,
 * which would otherwise carry the price, delivery, payment and bundle
 * conversions in one class and trip this repo's per-class
 * cyclomatic-complexity gate. No percent fields here, so nothing converts to
 * basis points; every field is money (left in the policy's currency unit) or
 * a plain count/flag.
 */
final class DeliveryBand
{
    private function __construct() {}

    /** @return array<string, mixed>|null null when no delivery policy is configured at all. */
    public static function from(?DeliveryPolicy $policy): ?array
    {
        if ($policy === null) {
            return null;
        }

        $block = [];
        if ($policy->freeShippingAboveNet !== null) {
            $block['freeShippingAboveNet'] = $policy->freeShippingAboveNet;
        }
        if ($policy->maxShippingWaiverNet !== null) {
            $block['maxShippingWaiverNet'] = $policy->maxShippingWaiverNet;
        }
        if ($policy->expeditedAllowed !== null) {
            $block['expeditedAllowed'] = $policy->expeditedAllowed;
        }
        if ($policy->committedLeadTimeDaysMin !== null) {
            $block['committedLeadTimeDaysMin'] = $policy->committedLeadTimeDaysMin;
        }

        return $block;
    }
}
