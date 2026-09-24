<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot;

/**
 * What MarginFloorClamp writes for each positive line once it has fired,
 * before the floor and cent rounding. Split out of OfferLanding to keep
 * per-class cyclomatic complexity within the gate.
 *
 * A per-line offer is absolute already, so it converts to its landing
 * (OfferLanding::of()). A quote-wide "p% off" converts from the
 * BASELINE-ANCHORED reference, not from the live prices: measured from live,
 * the same ask on the next round -- or a retry of this pass -- would take p%
 * off prices an earlier round already cut, and compound. Anchored, a repeat
 * reproduces the same line prices and nothing moves. Whether the clamp fires
 * at all is still decided on live (OfferLanding), because that is what the
 * percentage write does when it does not.
 */
final class OfferConversion
{
    private function __construct() {}

    /**
     * A quote-wide line goes to `min(reference × (1 − p/100), live × goodsFactor)`:
     * p% off its anchored price, but never above what it costs the buyer today,
     * so a line a human lowered is not raised. A line the reference does not
     * know is anchored on its live price.
     *
     * @param list<QuoteLineSnapshot> $liveLines the pre-write quote
     * @param list<QuoteLineSnapshot> $referenceLines the baseline-anchored quote (the live one on a first pass)
     *
     * @return array<string, float> lineItemId => net unit price to write
     */
    public static function of(ProposedOffer $offer, array $liveLines, array $referenceLines): array
    {
        if (($offer->price->linePricesNet ?? []) !== []) {
            return OfferLanding::of($offer, $liveLines);
        }

        $reference = [];
        foreach ($referenceLines as $line) {
            $reference[$line->lineItemId()] = $line->unitPriceNet;
        }

        $factor = OfferLanding::quoteWideFactor($offer);
        $goodsFactor = GoodsFactor::of($liveLines);
        $converted = [];
        foreach ($liveLines as $line) {
            $live = $line->unitPriceNet;
            if ($live <= 0.0) {
                continue;
            }

            $converted[$line->lineItemId()] = min(
                ($reference[$line->lineItemId()] ?? $live) * $factor,
                $live * $goodsFactor,
            );
        }

        return $converted;
    }
}
