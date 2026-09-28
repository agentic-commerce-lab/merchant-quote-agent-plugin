<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot;

/**
 * The percentage a quote-wide offer is WRITTEN as (design note 2026-09-28).
 *
 * The model's `p` is measured against the anchored baseline, but
 * SwagCommercial applies the written percentage to the LIVE line prices and
 * REPLACES any quote discount already there. Written as-is, `p` stacks on
 * lines an earlier round cut per line, and a hold (null, or a smaller `p`)
 * writes less than the buyer already holds. This is OfferConversion's anchored
 * target — `baseline × (1 − p/100)`, never above today's price — expressed as
 * one percentage on the live goods instead of as line prices. Its own class
 * to keep OfferConversion within the complexity gate.
 */
final class QuoteWidePercent
{
    private function __construct() {}

    /**
     * @param list<QuoteLineSnapshot> $liveLines the pre-write quote
     * @param list<QuoteLineSnapshot> $referenceLines the baseline-anchored quote (the live one on a first pass)
     */
    public static function of(ProposedOffer $offer, array $liveLines, array $referenceLines): float
    {
        $reference = [];
        foreach ($referenceLines as $line) {
            $reference[$line->lineItemId()] = $line->unitPriceNet;
        }

        $live = 0.0;
        $anchored = 0.0;
        foreach ($liveLines as $line) {
            if ($line->unitPriceNet <= 0.0) {
                continue;
            }

            $live += $line->unitPriceNet * $line->quantity;
            $anchored += ($reference[$line->lineItemId()] ?? $line->unitPriceNet) * $line->quantity;
        }

        if ($live <= 0.0) {
            return 0.0;
        }

        $factor = min(($anchored * OfferLanding::quoteWideFactor($offer)) / $live, GoodsFactor::of($liveLines));

        // Rounded so a plain first-pass 5% is written as 5.0, not 5.0000000000000044.
        return round((1 - max(0.0, $factor)) * 100, precision: 6);
    }
}
