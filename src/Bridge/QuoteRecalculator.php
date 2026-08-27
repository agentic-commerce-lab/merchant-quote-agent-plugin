<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use Shopware\Core\Framework\Context;

/**
 * @internal-dependency
 * Shopware\Commercial\B2B\QuoteManagement\Domain\SalesChannelContextRestorer\SalesChannelContextRestorer::restoreByQuote()
 * and Shopware\Commercial\B2B\QuoteManagement\Domain\Recalculation\QuoteCalculator::recalculate()
 * — neither is @internal, but QuoteCalculator::recalculate() takes a
 * SalesChannelContext, not a Context, and restoreByQuote() is the only
 * supported way to build one for an existing quote (SwagCommercial's own
 * controller does the same).
 */
final readonly class QuoteRecalculator
{
    public function __construct(
        private object $contextRestorer,
        private object $quoteCalculator,
    ) {}

    public function recalculate(string $quoteId, Context $context): void
    {
        /** @mago-expect analysis:ambiguous-object-method-access */
        $salesChannelContext = $this->contextRestorer->restoreByQuote($quoteId, $context);

        /** @mago-expect analysis:ambiguous-object-method-access */
        $this->quoteCalculator->recalculate($quoteId, $salesChannelContext);
    }
}
