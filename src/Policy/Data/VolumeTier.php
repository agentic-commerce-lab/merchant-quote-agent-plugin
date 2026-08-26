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
}
