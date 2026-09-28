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
}
