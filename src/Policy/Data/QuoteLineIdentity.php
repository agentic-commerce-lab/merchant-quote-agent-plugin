<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

final readonly class QuoteLineIdentity
{
    public function __construct(
        public string $lineItemId,
        public ?string $label = null,
        public ?string $productId = null,
        public ?string $unit = null,
    ) {}
}
