<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;

/** Maps a quote's `lineItems` association onto the bridge's read model. */
final class QuoteLineMapper
{
    /** @return list<QuoteLineSnapshot> */
    public function map(Entity $quote): array
    {
        $lineItems = $quote->get('lineItems');
        $lines = [];

        if (!is_iterable($lineItems)) {
            return $lines;
        }

        foreach ($lineItems as $lineItem) {
            if (!$lineItem instanceof Entity || $lineItem->get('deletedAt') !== null) {
                continue;
            }

            $lines[] = new QuoteLineSnapshot(
                identity: new QuoteLineIdentity(
                    lineItemId: (string) $lineItem->get('id'),
                    label: $this->nullableString($lineItem->get('label')),
                    productId: $this->nullableString($lineItem->get('referencedId')),
                ),
                quantity: (int) $lineItem->get('quantity'),
                unitPriceNet: (float) $lineItem->get('unitPrice'),
                totalNet: (float) $lineItem->get('totalPrice'),
                requestedUnitPrice: $lineItem->get('requestedPrice') === null
                    ? null
                    : (float) $lineItem->get('requestedPrice'),
            );
        }

        return $lines;
    }

    private function nullableString(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }
}
