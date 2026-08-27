<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Commercial;

use Shopware\Core\Framework\Context;

/**
 * @internal-dependency
 * Shopware\Commercial\B2B\QuoteManagement\Domain\Admin\QuoteManipulation::addProduct()
 * — @internal in SwagCommercial. A release can change it without notice;
 * tests/Integration/ is what catches that, not static analysis.
 */
final readonly class SwagCommercialProductAdder implements QuoteProductAdderInterface
{
    public function __construct(
        private object $quoteManipulation,
    ) {}

    #[\Override]
    public function addProduct(string $quoteId, string $productId, int $quantity, Context $context): void
    {
        /** @mago-expect analysis:ambiguous-object-method-access */
        $this->quoteManipulation->addProduct($quoteId, $productId, $quantity, $context);
    }
}
