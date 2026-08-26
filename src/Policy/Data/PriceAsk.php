<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class PriceAsk
{
    public function __construct(
        #[Assert\Range(min: 0, max: 100)]
        public ?float $additionalDiscountPercent = null,
        public ?bool $bestPriceRequested = null,
    ) {}
}
