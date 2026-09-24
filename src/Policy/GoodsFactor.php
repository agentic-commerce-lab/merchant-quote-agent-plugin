<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot;

/**
 * What share of its line price a positive line actually costs the buyer, once
 * the negative lines are taken off. A quote-wide percentage discount is a
 * negative line SwagCommercial generates (see QuoteBaselineLines), so this is
 * the factor that discount applies.
 *
 * Built from the lines rather than from `totalNet`, unlike NetFactor, because
 * `totalNet` carries shipping and would make every line look dearer than it is.
 */
final class GoodsFactor
{
    private function __construct() {}

    /** @param list<QuoteLineSnapshot> $lines */
    public static function of(array $lines): float
    {
        $positive = 0.0;
        $negative = 0.0;

        foreach ($lines as $line) {
            $total = $line->unitPriceNet * $line->quantity;
            if ($total > 0.0) {
                $positive += $total;
                continue;
            }

            $negative += $total;
        }

        return $positive > 0.0 ? min(1.0, max(0.0, ($positive + $negative) / $positive)) : 1.0;
    }
}
