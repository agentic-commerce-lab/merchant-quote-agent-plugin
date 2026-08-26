<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;

/**
 * Ported from `verifyTotals` in src/policy/offer-verification.ts.
 */
final class TotalsOfferVerifier
{
    /** @return list<string> */
    public function verify(
        QuoteSnapshot $reference,
        QuoteSnapshot $final,
        QuoteLimits $limits,
        float $allowedExtraDiscountNet,
    ): array {
        return array_values(array_filter(
            [
                DiscountTotalViolation::check($reference, $final, $limits, $allowedExtraDiscountNet),
                ValueCeilingViolation::check($final, $limits),
                CurrencyMismatchViolation::check($final, $limits),
            ],
            static fn(?string $v): bool => $v !== null,
        ));
    }
}
