<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot;

/**
 * A per-line offer is bounded line by line: never below the price band
 * relative to the line's price, never above it.
 *
 * Fix for issue #2(a): bounded against `referenceLines` — a pre-negotiation
 * snapshot the caller captures once and reuses across rounds — instead of
 * the TS original's `quoteLines` (the quote's CURRENT lines). Bounding
 * against the current lines let per-line discounts compound past
 * maxDiscountPercent over a multi-round negotiation; a fixed reference
 * makes each round's floor absolute, not relative to the last counter.
 *
 * Ported from `checkLinePrices` in src/policy/negotiate-authorize.ts.
 */
final class LinePriceOfferCheck
{
    /** @return list<string> */
    public function check(OfferedPrice $offer, NegotiationPolicy $policy): array
    {
        if ($offer->linePricesNet === null || $offer->linePricesNet === []) {
            return [];
        }

        $reference = $this->referenceByLineItemId($offer->referenceLines ?? []);
        $floorFactor = 1 - ($policy->price->maxDiscountPercent / 100);

        $violations = [];
        foreach ($offer->linePricesNet as $price) {
            $violation = LineReferenceViolation::check($price, $reference, $floorFactor, $policy);
            if ($violation !== null) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /**
     * @param list<QuoteLineSnapshot> $lines
     * @return array<string, QuoteLineSnapshot>
     */
    private function referenceByLineItemId(array $lines): array
    {
        $byId = [];
        foreach ($lines as $line) {
            $byId[$line->lineItemId()] = $line;
        }

        return $byId;
    }
}
