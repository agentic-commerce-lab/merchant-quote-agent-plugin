<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data;

final readonly class QuoteTotals
{
    public function __construct(
        public float $totalNet,
        public ?Discount $discount = null,
    ) {}
}
