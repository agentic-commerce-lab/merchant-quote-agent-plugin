<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Data\Discount;
use MerchantQuoteAgentPlugin\Bridge\Data\DiscountType;

/**
 * Maps a quote's `discount` JsonField (`{type, value}`) onto `Discount`.
 * `tryFrom()` rather than `from()`: an unrecognized/malformed value must not
 * throw out of a read path — it is treated as "no discount".
 */
final class QuoteDiscountMapper
{
    public function map(mixed $discount): ?Discount
    {
        if (
            !\is_array($discount)
            || !\is_string($discount['type'] ?? null)
            || !\is_numeric($discount['value'] ?? null)
        ) {
            return null;
        }

        $type = DiscountType::tryFrom($discount['type']);

        return $type === null ? null : new Discount($type, (float) $discount['value']);
    }
}
