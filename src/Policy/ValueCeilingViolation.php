<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;

/**
 * Ported from the value-ceiling half of `verifyTotals` in
 * src/policy/offer-verification.ts.
 */
final class ValueCeilingViolation
{
    public static function check(QuoteSnapshot $final, QuoteLimits $limits): ?string
    {
        $ceiling = $limits->valueCeiling;
        if ($ceiling === null || $final->totalNet <= ($ceiling->net + Epsilon::MONEY)) {
            return null;
        }

        return sprintf(
            'quote value %s exceeds the auto-reply limit %s',
            number_format($final->totalNet, decimals: 2, thousands_separator: ''),
            $ceiling->net,
        );
    }
}
