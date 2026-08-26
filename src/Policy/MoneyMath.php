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

    // PHP's round() breaks ties away from zero; the ported TS uses JS's
    // Math.round(), which breaks ties toward +Infinity. floor(x + 0.5)
    // replicates Math.round() exactly, including on negative values.
    public static function roundMoney(float $value): float
    {
        return floor(($value * 100) + 0.5) / 100;
    }
}
