<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\DeliveryPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\OfferedDelivery;

/**
 * Port of `checkFreeShip`/`freeShippingAllowed` in
 * src/policy/negotiate-authorize.ts.
 */
final class FreeShipOfferCheck
{
    public function check(OfferedDelivery $offer, float $orderTotalNet, ?DeliveryPolicy $delivery): ?string
    {
        if (!($offer->freeShipping ?? false)) {
            return null;
        }

        return $this->allowed($offer, $orderTotalNet, $delivery)
            ? null
            : 'free shipping is not permitted for this order';
    }

    private function allowed(OfferedDelivery $offer, float $orderTotalNet, ?DeliveryPolicy $delivery): bool
    {
        if ($delivery === null) {
            return false;
        }

        $byOrderSize =
            $delivery->freeShippingAboveNet !== null
            && ($orderTotalNet + Epsilon::RATE) >= $delivery->freeShippingAboveNet;
        $byWaiverCap =
            $delivery->maxShippingWaiverNet !== null
            && (
                $offer->shippingCostNet === null
                || $offer->shippingCostNet <= ($delivery->maxShippingWaiverNet + Epsilon::RATE)
            );

        return $byOrderSize || $byWaiverCap;
    }
}
