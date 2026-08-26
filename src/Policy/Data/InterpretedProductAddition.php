<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class InterpretedProductAddition
{
    public function __construct(
        public string $productRef,
        #[Assert\Positive]
        public int $quantity,
        #[Assert\PositiveOrZero]
        public ?float $targetUnitPrice = null,
    ) {}
}
