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

    /** @throws \TypeError|\ValueError */
    public static function fromArray(array $data): self
    {
        return new self(
            freeShippingAboveNet: OptionalShape::float($data, 'freeShippingAboveNet'),
            maxShippingWaiverNet: OptionalShape::float($data, 'maxShippingWaiverNet'),
            expeditedAllowed: OptionalShape::bool($data, 'expeditedAllowed'),
            committedLeadTimeDaysMin: OptionalShape::int($data, 'committedLeadTimeDaysMin'),
        );
    }
}
