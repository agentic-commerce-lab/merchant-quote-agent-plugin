<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

final class UnsupportedProductException extends \RuntimeException
{
    public static function variant(string $productId): self
    {
        return new self(sprintf(
            'Product "%s" is a variant or a variant parent. Adding one to a quote is refused: '
            . 'SwagCommercial\'s QuoteManipulation::addProducts() segfaults on this shop for '
            . 'such products (exit 139, no PHP error). Standalone products are unaffected.',
            $productId,
        ));
    }
}
