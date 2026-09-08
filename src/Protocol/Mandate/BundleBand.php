<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Mandate;

use MerchantQuoteAgentPlugin\Policy\Data\BundlePolicy;
use MerchantQuoteAgentPlugin\Policy\Data\VolumeTier;

/**
 * The bundle block of `negotiation_bands` — split out of NegotiationBands for
 * the same reason as DeliveryBand and PaymentBand. Every volume tier's
 * `discountPercent` converts to `discountBps`.
 */
final class BundleBand
{
    private function __construct() {}

    /** @return array<string, mixed>|null null when no bundle policy is configured at all. */
    public static function from(?BundlePolicy $policy): ?array
    {
        if ($policy === null) {
            return null;
        }

        if ($policy->volumeTiers === []) {
            return [];
        }

        return [
            'volumeTiers' => array_map(static fn(VolumeTier $tier): array => [
                'minQty' => $tier->minQty,
                'discountBps' => (int) round($tier->discountPercent * 100),
            ], $policy->volumeTiers),
        ];
    }
}
