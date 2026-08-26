<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class DeliveryAsk
{
    public function __construct(
        public ?bool $freeShipping = null,
        #[Assert\PositiveOrZero]
        public ?float $shippingCostNet = null,
        public ?bool $expedited = null,
        #[Assert\PositiveOrZero]
        public ?int $requestedLeadTimeDays = null,
    ) {}
}
