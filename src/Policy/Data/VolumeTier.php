<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class VolumeTier
{
    public function __construct(
        #[Assert\Positive]
        public int $minQty,
        #[Assert\Range(min: 0, max: 100)]
        public float $discountPercent,
    ) {}

    /** @throws \TypeError|\ValueError */
    public static function fromArray(array $data): self
    {
        return new self(
            minQty: RequiredShape::int($data, 'minQty'),
            discountPercent: RequiredShape::float($data, 'discountPercent'),
        );
    }
}
