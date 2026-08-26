<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteDecision;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationDetails;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;

/**
 * Fix for issue #2(b): the value ceiling now carries its own currency; a
 * mismatch always escalates, checked before any discount band.
 */
final class CurrencyMismatchEscalation
{
    public function check(QuoteSnapshot $snapshot, QuoteLimits $limits): ?QuoteDecision
    {
        $ceilingCurrency = $limits->valueCeiling?->currencyIso;
        if ($ceilingCurrency === null || $snapshot->currencyIso === $ceilingCurrency) {
            return null;
        }

        return QuoteDecision::escalate(new QuoteEscalationDetails(
            reason: QuoteEscalationReason::CurrencyMismatch,
            requestedDiscountPercent: MoneyMath::requestedDiscount($snapshot),
        ));
    }
}
