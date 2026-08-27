<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Commercial;

use Shopware\Core\Framework\Context;

/** Mirrors QuoteManipulation::addProduct(), which is @internal in SwagCommercial. */
interface QuoteProductAdderInterface
{
    public function addProduct(string $quoteId, string $productId, int $quantity, Context $context): void;
}
