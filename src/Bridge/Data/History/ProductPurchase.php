<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data\History;

/** What the company paid for one SKU once, across any employee. */
final readonly class ProductPurchase
{
    public function __construct(
        public ?\DateTimeImmutable $orderedAt,
        public int $quantity,
        public float $unitPriceNet,
    ) {}
}
