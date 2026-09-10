<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;

/**
 * Line unit prices are read in the cart's display space (gross on
 * gross-calculated carts) while totalNet is always net — and the two
 * snapshots being compared are not guaranteed to share a space
 * (recalculation can flip it). Normalize each snapshot's line prices to net
 * via its own total/line-sum ratio so the per-line comparison is
 * price-space-proof.
 *
 * Ported from `netFactor` in the retired TS agent (policy/offer-verification.ts).
 */
final class NetFactor
{
    public static function of(QuoteSnapshot $snapshot): float
    {
        $rawLineSum = 0.0;
        foreach ($snapshot->lines as $line) {
            $rawLineSum += $line->unitPriceNet * $line->quantity;
        }

        return $rawLineSum > 0 && $snapshot->totalNet > 0 ? $snapshot->totalNet / $rawLineSum : 1.0;
    }
}
