<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;

/**
 * Shared money-rounding and discount-percentage arithmetic, used across the
 * policy deciders. Split into its own class to keep per-class cyclomatic
 * complexity within the quality gate.
 */
final class MoneyMath
{
    public static function requestedDiscount(QuoteSnapshot $snapshot): ?float
    {
        if ($snapshot->buyerTargetNet === null || $snapshot->totalNet <= 0) {
            return null;
        }

        return (($snapshot->totalNet - $snapshot->buyerTargetNet) / $snapshot->totalNet) * 100;
    }

    /**
     * How far cent rounding alone can move requestedDiscount(), in percentage
     * points: one cent per unit on the positive lines. The baseline and the
     * buyer's target are both rounded to the cent per unit after the tax comes
     * off (QuoteLineNet), so 15% off 865.40 gross, 618.14 net a unit, reads
     * 15.0004% on ten units. A limit compared without this slack excludes an
     * ask at exactly the limit.
     */
    public static function roundingSlackPercent(QuoteSnapshot $snapshot): float
    {
        if ($snapshot->totalNet <= 0) {
            return Epsilon::RATE;
        }

        $units = 0;
        foreach ($snapshot->lines as $line) {
            $units += $line->unitPriceNet > 0.0 ? $line->quantity : 0;
        }

        return max(Epsilon::RATE, ((Epsilon::MONEY * $units) / $snapshot->totalNet) * 100);
    }

    // PHP's round() breaks ties away from zero; the ported TS uses JS's
    // Math.round(), which breaks ties toward +Infinity. floor(x + 0.5)
    // replicates Math.round() exactly, including on negative values.
    public static function roundMoney(float $value): float
    {
        return floor(($value * 100) + 0.5) / 100;
    }

    /** Rounds down to the cent; round() first, so 9.9999999999 stays 10.00 instead of becoming 9.99. */
    public static function floorToCent(float $value): float
    {
        return floor(round($value * 100, precision: 6)) / 100;
    }
}
