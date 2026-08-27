<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;

/**
 * Maps a quote's `lineItems` association onto the bridge's read model.
 *
 * Everything here is shape; the gross→net conversion the read model needs
 * lives in QuoteLineNet, which explains why it is needed at all.
 */
final class QuoteLineMapper
{
    /** @return list<QuoteLineSnapshot> */
    public function map(Entity $quote): array
    {
        $lineItems = $quote->get('lineItems');

        if (!is_iterable($lineItems)) {
            return [];
        }

        $taxStatus = (string) $quote->get('taxStatus');
        $lines = [];

        foreach ($lineItems as $lineItem) {
            if (!$lineItem instanceof Entity || $lineItem->get('deletedAt') !== null) {
                continue;
            }

            $lines[] = $this->line($lineItem, QuoteLineNet::of($lineItem, $taxStatus));
        }

        return $lines;
    }

    private function line(Entity $lineItem, QuoteLineNet $net): QuoteLineSnapshot
    {
        return new QuoteLineSnapshot(
            identity: new QuoteLineIdentity(
                lineItemId: (string) $lineItem->get('id'),
                label: $this->nullableString($lineItem->get('label')),
                productId: $this->nullableString($lineItem->get('referencedId')),
            ),
            quantity: (int) $lineItem->get('quantity'),
            unitPriceNet: $net->unitPrice,
            totalNet: $net->total,
            requestedUnitPrice: $net->requestedUnitPrice,
        );
    }

    private function nullableString(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }
}
