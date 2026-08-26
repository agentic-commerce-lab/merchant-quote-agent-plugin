<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class InterpretedLineChange
{
    public function __construct(
        public string $lineItemId,
        public ?int $quantity = null,
        #[Assert\PositiveOrZero]
        public ?float $targetUnitPrice = null,
        public ?bool $remove = null,
    ) {}
}
