<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\DeliveryPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\OfferedDelivery;

/**
 * Port of `checkLeadTime` in src/policy/negotiate-authorize.ts.
 */
final class LeadTimeOfferCheck
{
    private const float EPSILON = 1e-6;

    public function check(OfferedDelivery $offer, ?DeliveryPolicy $delivery): ?string
    {
        if ($offer->committedLeadTimeDays === null) {
            return null;
        }

        $floor = $delivery?->committedLeadTimeDaysMin;
        if ($floor === null) {
            return 'committed lead time is not configured';
        }

        return ($offer->committedLeadTimeDays + self::EPSILON) < $floor
            ? sprintf('lead time %dd is faster than the %dd floor', $offer->committedLeadTimeDays, $floor)
            : null;
    }
}
