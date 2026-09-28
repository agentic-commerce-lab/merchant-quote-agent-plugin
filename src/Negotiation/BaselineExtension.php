<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot as PolicyLine;

/**
 * The arithmetic behind QuoteBaselineLines::extendedWith() (#54). Split out
 * to keep that class within the complexity gate, the same way BaselineRow
 * was split out of QuoteBaseline.
 */
final class BaselineExtension
{
    private function __construct() {}

    /**
     * Live lines the baseline does not already hold, excluding negative ones
     * — see QuoteBaselineLines::extendedWith() for why negative lines are
     * never taken.
     *
     * Each appended id is marked known as it is added, so a duplicate
     * `lineItemId` in $live (never seen in practice) cannot be appended
     * twice and double-counted in the returned value.
     *
     * @param list<PolicyLine> $stored
     * @param list<PolicyLine> $live
     *
     * @return list<PolicyLine>
     */
    public static function unknownLines(array $stored, array $live): array
    {
        $known = [];

        foreach ($stored as $line) {
            $known[$line->lineItemId()] = true;
        }

        $added = [];

        foreach ($live as $line) {
            if (isset($known[$line->lineItemId()]) || $line->unitPriceNet < 0.0) {
                continue;
            }

            $known[$line->lineItemId()] = true;
            $added[] = $line;
        }

        return $added;
    }

    /**
     * $added's value expressed in $storedTotalNet's price space, so
     * NetFactor::of() reads the same ratio before and after — with
     * f = storedTotalNet / stored line sum, (f*S + f*v) / (S + v) is f
     * exactly — WHEN $storedSum and $storedTotalNet are both usable (the
     * normal branch below).
     *
     * When either is not (<= 0), there is no ratio to express the value in,
     * so it is added unscaled — the same fallback NetFactor::of() itself
     * takes (1.0) when it has no usable ratio either. That branch does NOT
     * preserve NetFactor: the ratio a caller reads afterwards is
     * ($storedTotalNet + $raw) / $raw, not whatever it was before.
     *
     * $added's own `totalNet` is deliberately not used — line totals are read
     * in the cart's DISPLAY space, gross on a gross-calculated cart, while the
     * quote total is always net. That mismatch is the whole reason NetFactor
     * exists, and adding one to the other would reintroduce it.
     *
     * @param list<PolicyLine> $stored
     * @param list<PolicyLine> $added
     */
    public static function scaledValue(array $stored, float $storedTotalNet, array $added): float
    {
        $raw = 0.0;

        foreach ($added as $line) {
            $raw += $line->unitPriceNet * $line->quantity;
        }

        $storedSum = 0.0;

        foreach ($stored as $line) {
            // Positive lines only, as NetFactor::of() sums them.
            $storedSum += max(0.0, $line->unitPriceNet * $line->quantity);
        }

        // Mirrors NetFactor's own 1.0 fallback: with no usable stored ratio
        // there is nothing to express the value in but itself.
        return $storedSum > 0 && $storedTotalNet > 0 ? $raw * ($storedTotalNet / $storedSum) : $raw;
    }
}
