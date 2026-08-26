<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

final readonly class QuoteLinePrice
{
    public function __construct(
        public string $lineItemId,
        public float $unitPriceNet,
    ) {}
}
