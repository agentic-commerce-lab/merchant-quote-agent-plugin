<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\MoneyMath;

/**
 * What the quote's net total would become if $price were applied.
 *
 * In production this number comes from OfferApplier writing the offer and
 * then asking the database (`recordApplied()` reads `$applied->after`), which
 * is exactly the step a replay is forbidden to take. `ProposedOffer::$orderTotalNet`
 * cannot stand in for it either: `OfferTerms::toOffer()`'s own docblock says
 * the field is "the order total, which only the caller ... knows" -- it is the
 * BEFORE total the caller passed in, carried through LinePriceNormalizer and
 * OfferLevelMirror unchanged, never the result of applying the offer.
 *
 * A quote-wide offer is one multiplication. A per-line offer's own
 * `discountPercent` is null (OfferLevelMirror clears it on purpose, #47), so
 * the total is the only figure both offer shapes share -- which is why
 * GrantedDiscount::of() is fed totals computed here rather than the offer's
 * percentage.
 */
final class OfferedTotal
{
    private function __construct() {}

    /** Null only for an offer that named neither a discount nor a line price -- nothing to measure. */
    public static function of(float $totalNetBefore, OfferedPrice $price): ?float
    {
        if ($price->discountPercent !== null) {
            return MoneyMath::roundMoney($totalNetBefore * (1 - ($price->discountPercent / 100)));
        }

        if ($price->linePricesNet === null) {
            return null;
        }

        return MoneyMath::roundMoney($totalNetBefore - self::linePriceDelta($price));
    }

    /**
     * The sum of what each offered line price takes off its reference line,
     * measured against `referenceLines` (the pre-negotiation snapshot the
     * proposer attaches) rather than assuming the total equals their sum --
     * the anchor for "before" is $totalNetBefore, not a recomputation of it.
     */
    private static function linePriceDelta(OfferedPrice $price): float
    {
        $reference = [];
        foreach ($price->referenceLines ?? [] as $line) {
            $reference[$line->lineItemId()] = $line;
        }

        $delta = 0.0;
        foreach ($price->linePricesNet ?? [] as $offered) {
            $line = $reference[$offered->lineItemId] ?? null;
            if ($line === null) {
                continue;
            }

            $delta += ($line->unitPriceNet - $offered->unitPriceNet) * $line->quantity;
        }

        return $delta;
    }
}
