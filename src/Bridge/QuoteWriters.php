<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Commercial\QuoteProductAdderInterface;

/**
 * The gateway's write-side collaborators, grouped so the gateway itself stays
 * inside the five-constructor-parameter gate as the plan keeps adding writes.
 * Read stays out: there is exactly one reader and the gateway holds it directly.
 */
final readonly class QuoteWriters
{
    public function __construct(
        public QuoteLineItemWriter $lineItems,
        public QuoteWriter $quote,
        public QuoteRecalculator $recalculator,
        public QuoteProductAdderInterface $productAdder,
    ) {}
}
