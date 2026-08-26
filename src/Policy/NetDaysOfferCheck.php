<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\OfferedPayment;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentPolicy;

/**
 * Port of `checkNetDays` in src/policy/negotiate-authorize.ts.
 */
final class NetDaysOfferCheck
{
    private const float EPSILON = 1e-6;

    public function check(OfferedPayment $offer, ?PaymentPolicy $payment): ?string
    {
        if ($offer->netDays === null) {
            return null;
        }

        $ceiling = $payment?->maxNetDays;
        if ($ceiling === null) {
            return 'numeric net-days is not configured';
        }

        return $offer->netDays > ($ceiling + self::EPSILON)
            ? sprintf('net %d days exceeds the %d-day ceiling', $offer->netDays, $ceiling)
            : null;
    }
}
