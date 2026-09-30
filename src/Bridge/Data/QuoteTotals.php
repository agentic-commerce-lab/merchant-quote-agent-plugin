<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data;

/**
 * `totalNet` is what every policy decision is measured in. `totalGross` is
 * Shopware's `amountTotal` — the figure the buyer actually owes, tax included —
 * and is the ONLY one that may be quoted at a buyer.
 *
 * The distinction is spelled out because a 19%-tax quote whose buyer owed
 * 8226.60 was told "your new total is 6913.11 EUR", because
 * the reply reached for the net total. On a 0%-tax quote the two are equal and
 * the bug is invisible, which is how it survived.
 */
final readonly class QuoteTotals
{
    public function __construct(
        public float $totalNet,
        public ?Discount $discount = null,
        /** Null only when a caller genuinely has no gross figure; the reply then falls back to net. */
        public ?float $totalGross = null,
    ) {}

    /** What to show the buyer. */
    public function buyerFacingTotal(): float
    {
        return $this->totalGross ?? $this->totalNet;
    }
}
