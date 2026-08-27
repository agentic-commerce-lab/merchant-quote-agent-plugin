<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data;

/**
 * One line item's requested change. A null field means "leave it alone", so a
 * batch can reprice one line, requantify another and remove a third.
 */
final readonly class QuoteLineItemChange
{
    public function __construct(
        public string $lineItemId,
        public ?float $unitPriceNet = null,
        public ?int $quantity = null,
        public ?bool $remove = null,
    ) {}

    public function touchesPrice(): bool
    {
        return $this->unitPriceNet !== null && !$this->isRemoval();
    }

    public function isRemoval(): bool
    {
        return $this->remove === true;
    }
}
