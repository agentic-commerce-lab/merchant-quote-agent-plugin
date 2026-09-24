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
 * It works out what each positive line would actually cost the buyer after the
 * write (OfferLanding). If every floored line lands at or above its floor, it
 * returns null and the offer goes out exactly as proposed. Otherwise it returns
 * a complete per-line offer, with the old discount folded into every line's own
 * price, so the caller can reset that discount to 0% without taking anything
 * away from the buyer.
 */
final class MarginFloorClamp
{
    private function __construct() {}

    /**
     * @param list<QuoteLineSnapshot> $liveLines the pre-write quote, never the baseline
     * @param array<string, float> $floors lineItemId => effective floor net (MarginFloors::of)
     */
    public static function clamp(ProposedOffer $offer, array $liveLines, array $floors): ?ProposedOffer
    {
        $landing = OfferLanding::of($offer, $liveLines);

        if (!self::binds($landing, $floors)) {
            return null;
        }

        $prices = [];
        foreach ($landing as $lineItemId => $price) {
            $prices[] = new QuoteLinePrice(lineItemId: $lineItemId, unitPriceNet: max(
                MoneyMath::roundMoney($price),
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
