<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class DeliveryPolicy
{
    public function __construct(
        #[Assert\PositiveOrZero]
        public ?float $freeShippingAboveNet = null,
        #[Assert\PositiveOrZero]
        public ?float $maxShippingWaiverNet = null,
        public ?bool $expeditedAllowed = null,
        #[Assert\PositiveOrZero]
        public ?int $committedLeadTimeDaysMin = null,
    ) {}
}
