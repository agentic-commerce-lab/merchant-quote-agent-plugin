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

    /** @throws \TypeError|\ValueError */
    public static function fromArray(array $data): self
    {
        return new self(
            freeShipping: OptionalShape::bool($data, 'freeShipping'),
            shippingCostNet: OptionalShape::float($data, 'shippingCostNet'),
            expedited: OptionalShape::bool($data, 'expedited'),
            requestedLeadTimeDays: OptionalShape::int($data, 'requestedLeadTimeDays'),
        );
    }
}
