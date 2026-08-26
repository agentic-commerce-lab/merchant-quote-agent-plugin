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

    public static function roundMoney(float $value): float
    {
        return round($value * 100) / 100;
    }
}
