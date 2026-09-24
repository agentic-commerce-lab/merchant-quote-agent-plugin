<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;

/**
 * The lowest net unit price each line may be offered at (spec 2026-09-24):
 * `purchase × (1 + margin/100)`, rounded UP to the cent, and never above what
 * the line costs the buyer today, rounded DOWN to the cent. That `min` is what
 * keeps the floor from ever raising a price: a line already priced below its
 * floor gets no further discount, but is not pushed back up either.
 */
final class MarginFloors
{
    private function __construct() {}

    /**
     * @param array<string, float> $purchaseNetByProduct productId => net purchase price per unit
     *
     * @return array<string, float> lineItemId => effective floor net
     */
    public static function of(QuoteSnapshot $live, array $purchaseNetByProduct, float $marginPercent): array
    {
        $goodsFactor = GoodsFactor::of($live->lines);
        $floors = [];

        foreach ($live->lines as $line) {
            $purchase = $purchaseNetByProduct[$line->identity->productId ?? ''] ?? null;

            if ($purchase === null || $line->unitPriceNet <= 0.0) {
                continue;
            }

            $floors[$line->lineItemId()] = min(
                self::ceilToCent($purchase * (1 + ($marginPercent / 100))),
                MoneyMath::floorToCent($line->unitPriceNet * $goodsFactor),
            );
        }

        return $floors;
    }

    /** round() first, so 110.00000000000001 stays 110.00 instead of becoming 110.01. */
    private static function ceilToCent(float $value): float
    {
        return ceil(round($value * 100, precision: 6)) / 100;
    }
}
