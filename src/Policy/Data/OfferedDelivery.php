<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class OfferedDelivery
{
    public function __construct(
        public ?bool $freeShipping = null,
        public ?bool $expedited = null,
        #[Assert\PositiveOrZero]
        public ?int $committedLeadTimeDays = null,
        #[Assert\PositiveOrZero]
        public ?float $shippingCostNet = null,
    ) {}
}
