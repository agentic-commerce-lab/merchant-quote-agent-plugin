<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\OfferedPayment;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentPolicy;

/**
 * Port of `checkDeposit` in src/policy/negotiate-authorize.ts.
 */
final class DepositOfferCheck
{
    public function check(OfferedPayment $offer, ?PaymentPolicy $payment): ?string
    {
        if ($offer->depositPercent === null) {
            return null;
        }

        $minimum = $payment?->minDepositPercent;
        if ($minimum === null) {
            return 'deposit terms are not configured';
        }

        return ($offer->depositPercent + Epsilon::RATE) < $minimum
            ? sprintf('deposit %s%% is below the %s%% minimum', $offer->depositPercent, $minimum)
            : null;
    }
}
