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
        public ?float $requestedUnitPriceNet = null,
    ) {}

    public function touchesPrice(): bool
    {
        return $this->unitPriceNet !== null && !$this->isRemoval();
    }

    /**
     * The buyer's ask, not the answer to it — a per-unit target mirrored onto
     * the line so the quote shows what was requested beside what is quoted.
     * NET, like every other price on this DTO; the writer converts it into the
     * quote's own tax space, which is where Shopware stores it.
     */
    public function touchesRequestedPrice(): bool
    {
        return $this->requestedUnitPriceNet !== null && !$this->isRemoval();
    }

    public function isRemoval(): bool
    {
        return $this->remove === true;
    }
}
