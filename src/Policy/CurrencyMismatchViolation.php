<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;

/**
 * Fix for issue #2(b), carried into the post-modification gate: the saved
 * offer's currency must match the value ceiling's currency, same as the
 * pre-pricing check in QuoteDecider/CurrencyMismatchEscalation.
 */
final class CurrencyMismatchViolation
{
    public static function check(QuoteSnapshot $final, QuoteLimits $limits): ?string
    {
        $ceilingCurrency = $limits->valueCeiling?->currencyIso;
        if ($ceilingCurrency === null || $final->currencyIso === $ceilingCurrency) {
            return null;
        }

        return sprintf(
            'quote currency %s does not match the auto-reply limit currency %s',
            $final->currencyIso,
            $ceilingCurrency,
        );
    }
}
