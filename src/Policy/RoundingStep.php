<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

/**
 * Multiples of a merchant's rounding step (spec 2026-09-28), with the same
 * float-noise guard as MoneyMath::floorToCent(): round() first, so 7.5 / 0.5
 * = 14.999… still counts as 15 and 7.5 stays 7.5.
 */
final class RoundingStep
{
    private function __construct() {}

    public static function down(float $value, float $step): float
    {
        return round(floor(round($value / $step, precision: 6)) * $step, precision: 6);
    }

    public static function up(float $value, float $step): float
    {
        return round(ceil(round($value / $step, precision: 6)) * $step, precision: 6);
    }

    /**
     * Rule 2: an offer within 0.01 percentage points of what the buyer asked
     * (AskedDiscountCeiling::percent(), which already folds a percentage,
     * line prices and a budget into one figure) is their own. Two decimals,
     * not Epsilon::RATE: a budget converts to a long percentage the model
     * answers to two places, and RATE would round the buyer's own budget away.
     */
    public static function isBuyersFigure(?float $offeredPercent, ?float $askedPercent): bool
    {
        return (
            $offeredPercent !== null
            && $askedPercent !== null
            && abs($offeredPercent - $askedPercent) <= Epsilon::MONEY
        );
    }
}
