<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data;

final readonly class Discount
{
    public function __construct(
        public DiscountType $type,
        public float $value,
    ) {}
}
