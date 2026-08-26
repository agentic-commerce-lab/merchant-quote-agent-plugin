<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

final readonly class DeliveryDecision
{
    public function __construct(
        public Band $band,
        public ?bool $freeShippingGranted = null,
        public ?bool $expeditedGranted = null,
        public ?int $committedLeadTimeDays = null,
        public ?string $reason = null,
    ) {}
}
