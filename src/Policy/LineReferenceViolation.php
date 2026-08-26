<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLinePrice;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot;

/**
 * One proposed line price checked against its reference line. Split out of
 * LinePriceOfferCheck to keep per-class cyclomatic complexity within the
 * quality gate.
 */
final class LineReferenceViolation
{
    private const float EPSILON = 1e-6;

    /** @param array<string, QuoteLineSnapshot> $reference */
    public static function check(
        QuoteLinePrice $price,
        array $reference,
        float $floorFactor,
        NegotiationPolicy $policy,
    ): ?string {
        $line = $reference[$price->lineItemId] ?? null;
        if ($line === null) {
            return sprintf('line %s is not on this quote', $price->lineItemId);
        }

        if ($price->unitPriceNet > ($line->unitPriceNet + self::EPSILON)) {
            return sprintf('line "%s" priced above its current price', $line->label() ?? $price->lineItemId);
        }

        if (($price->unitPriceNet + self::EPSILON) < ($line->unitPriceNet * $floorFactor)) {
            return sprintf(
                'line "%s" price %s exceeds the %s%% limit',
                $line->label() ?? $price->lineItemId,
                $price->unitPriceNet,
                $policy->price->maxDiscountPercent,
            );
        }

        return null;
    }
}
