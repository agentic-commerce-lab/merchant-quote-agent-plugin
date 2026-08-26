<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\OfferedPayment;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentPolicy;

/**
 * Port of `checkPaymentTerm` in src/policy/negotiate-authorize.ts.
 */
final class PaymentTermOfferCheck
{
    public function check(OfferedPayment $offer, ?PaymentPolicy $payment): ?string
    {
        if ($offer->paymentTerm === null) {
            return null;
        }

        return in_array($offer->paymentTerm, $payment->allowedTerms ?? [], strict: true)
            ? null
            : sprintf('payment term %s is not in the allowed set', $offer->paymentTerm->value);
    }
}
