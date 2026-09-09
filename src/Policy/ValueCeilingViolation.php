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

        if ($ceiling === null) {
            return null;
        }

        $ceilingNet = $ceiling->netFor($final->currencyIso);

        // Mirrors QuoteBandDecider: an unconfigured currency is an unknown
        // ceiling, and the post-modification gate must not let through what
        // the pre-pricing check would have escalated.
        if ($ceilingNet === null) {
            return sprintf('no auto-reply limit is configured for %s', $final->currencyIso);
        }

        if ($final->totalNet <= ($ceilingNet + Epsilon::MONEY)) {
            return null;
        }

        return sprintf(
            'quote value %s exceeds the auto-reply limit %s',
            number_format($final->totalNet, decimals: 2, thousands_separator: ''),
            $ceilingNet,
        );
    }
}
