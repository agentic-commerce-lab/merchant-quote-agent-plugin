<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Mandate;

use MerchantQuoteAgentPlugin\Policy\Data\PaymentPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentTerm;

/**
 * The payment block of `negotiation_bands` — split out of NegotiationBands
 * for the same reason as DeliveryBand. `minDepositPercent` is the one
 * percent field here, so it is the one field converted to basis points
 * (`minDepositBps`); `maxNetDays` is a plain count and `allowedTerms` is an
 * enum list.
 */
final class PaymentBand
{
    private function __construct() {}

    /** @return array<string, mixed>|null null when no payment policy is configured at all. */
    public static function from(?PaymentPolicy $policy): ?array
    {
        if ($policy === null) {
            return null;
        }

        $block = [];
        if ($policy->allowedTerms !== []) {
            $block['allowedTerms'] = array_map(
                static fn(PaymentTerm $term): string => $term->value,
                $policy->allowedTerms,
            );
        }
        if ($policy->maxNetDays !== null) {
            $block['maxNetDays'] = $policy->maxNetDays;
        }
        if ($policy->minDepositPercent !== null) {
            $block['minDepositBps'] = (int) round($policy->minDepositPercent * 100);
        }

        return $block;
    }
}
