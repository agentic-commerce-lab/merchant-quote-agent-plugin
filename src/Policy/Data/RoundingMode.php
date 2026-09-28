<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

/**
 * Whether the agent's own figures come out round (spec 2026-09-28). The
 * values are config.xml's option ids for `roundingMode`, and
 * ConfigXmlSchemaTest pins the two lists together.
 */
enum RoundingMode: string
{
    case Off = 'off';

    /** The model's discount percentage, floored to the step (percentage points). */
    case DiscountPercent = 'discount_percent';

    /** A quote-wide offer's buyer-facing total, raised to the step (currency units). */
    case QuoteTotal = 'quote_total';

    /**
     * The step this mode rounds to under the merchant's `$set` mode and
     * `$step`: null for Off (even with a step set), for any mode but the one
     * set, and for a blank or zero step.
     */
    public function stepUnder(self $set, ?float $step): ?float
    {
        $step ??= 0.0;

        return $this !== self::Off && $this === $set && $step > 0.0 ? $step : null;
    }
}
