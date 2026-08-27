<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Commercial\QuoteProductAdderInterface;

/**
 * The gateway's write-side collaborators that change what the quote COSTS —
 * lines, quote-level fields, the aggregate, and products added through
 * SwagCommercial. Everything here needs recalculate() and the optimistic
 * revision check around it, which is the seam: the two writes that carry no
 * money live in QuoteLifecycleWriters instead. Grouped so the gateway itself
 * stays inside the five-constructor-parameter gate.
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
