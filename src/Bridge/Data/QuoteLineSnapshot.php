<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data;

/**
 * `unitPriceNet` is deliberately unconstrained in sign: Shopware-generated
 * lines (the quote-discount line) are legitimately negative.
 */
final readonly class QuoteLineSnapshot
{
    public function __construct(
        public QuoteLineIdentity $identity,
        public int $quantity,
        public float $unitPriceNet,
        public float $totalNet,
        public ?float $requestedUnitPrice = null,
    ) {}
}
