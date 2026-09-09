<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

/** Binds a reader to the customer from the serviced quote, never model-supplied text. */
interface CustomerHistoryFactoryInterface
{
    public function for(string $customerId): CustomerHistoryInterface;
}
