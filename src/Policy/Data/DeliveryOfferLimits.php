<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

final readonly class DeliveryOfferLimits
{
    public function __construct(
        public bool $freeShippingAllowed = false,
        public bool $expeditedAllowed = false,
        public ?int $committedLeadTimeDaysMin = null,
    ) {}
}
