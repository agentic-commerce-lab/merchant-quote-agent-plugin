<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\DeliveryPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\OfferedDelivery;

/**
 * Port of `checkExpedited` in src/policy/negotiate-authorize.ts.
 */
final class ExpeditedOfferCheck
{
    public function check(OfferedDelivery $offer, ?DeliveryPolicy $delivery): ?string
    {
        return ($offer->expedited ?? false) && !($delivery->expeditedAllowed ?? false)
            ? 'expedited shipping is not allowed'
            : null;
    }
}
