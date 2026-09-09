<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data\History;

/** One line of a past order. What the company actually buys, as opposed to what it spends. */
final readonly class OrderLineEntry
{
    public function __construct(
        public string $label,
        public int $quantity,
        public float $unitPriceNet,
    ) {}
}
