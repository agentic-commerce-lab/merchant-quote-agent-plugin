<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLinePrice;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot;

/**
 * Raises an offer to the minimum-margin floor (spec 2026-09-24) instead of
 * letting it escalate.
 *
 * Whether it fires is decided on the LIVE quote: what each positive line would
 * actually cost the buyer if the offer were written as proposed
 * (OfferLanding::of()). If every floored line lands at or above its floor, it
 * returns null and the offer goes out exactly as proposed.
 *
 * Once it fires, the prices it writes come from OfferConversion::of(): a
 * per-line offer as named, a quote-wide "p% off" measured from the
 * BASELINE-ANCHORED reference and capped at each line's live price. Measuring
 * that conversion on live prices would make it compound: the buyer repeating
 * the ask, or a retry of the pass, would take p% off what the previous round
 * already wrote. Anchored, a repeat reproduces the same prices and nothing
 * moves.
 *
 * It returns a complete per-line offer, with the old discount folded into
 * every line's own price, so the caller can reset that discount to 0% without
 * taking anything away from the buyer. Prices round DOWN to the cent: rounding
 * each unit to the nearest cent could lift the quote total by up to half a
 * cent per unit, which OfferApplier's never-raise check would escalate.
 */
final class MarginFloorClamp
{
    private function __construct() {}

    /**
     * @param list<QuoteLineSnapshot> $liveLines the pre-write quote, never the baseline; decides whether the floor binds
     * @param list<QuoteLineSnapshot> $referenceLines the baseline-anchored quote (live on a first pass); a quote-wide conversion is measured from it
     * @param array<string, float> $floors lineItemId => effective floor net (MarginFloors::of)
     */
    public static function clamp(
        ProposedOffer $offer,
        array $liveLines,
        array $referenceLines,
        array $floors,
    ): ?ProposedOffer {
        $landing = OfferLanding::of($offer, $liveLines);

        if (!self::binds($landing, $floors)) {
            return null;
        }

        $prices = [];
        foreach (OfferConversion::of($offer, $liveLines, $referenceLines) as $lineItemId => $price) {
            $prices[] = new QuoteLinePrice(lineItemId: $lineItemId, unitPriceNet: max(
                MoneyMath::floorToCent($price),
                $floors[$lineItemId] ?? 0.0,
            ));
        }

        return new ProposedOffer(
            orderTotalNet: $offer->orderTotalNet,
            price: new OfferedPrice(linePricesNet: $prices, referenceLines: $offer->price->referenceLines),
        );
    }

    /**
     * @param array<string, float> $landing
     * @param array<string, float> $floors
     */
    private static function binds(array $landing, array $floors): bool
    {
        foreach ($floors as $lineItemId => $floor) {
            if (($landing[$lineItemId] ?? INF) < ($floor - Epsilon::MONEY)) {
                return true;
            }
        }

        return false;
    }
}
