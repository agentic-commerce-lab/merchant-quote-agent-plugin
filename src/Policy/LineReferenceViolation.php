<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLinePrice;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot;

/**
 * Validates a single offered line price against its reference line on the
 * quote: the line must exist, its price must not exceed the current price,
 * and it must not undercut the policy's maxDiscountPercent floor.
 */
final class LineReferenceViolation
{
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

        if ($price->unitPriceNet > ($line->unitPriceNet + Epsilon::MONEY)) {
            return sprintf('line "%s" priced above its current price', $line->label() ?? $price->lineItemId);
        }

        if (($price->unitPriceNet + Epsilon::MONEY) < ($line->unitPriceNet * $floorFactor)) {
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
