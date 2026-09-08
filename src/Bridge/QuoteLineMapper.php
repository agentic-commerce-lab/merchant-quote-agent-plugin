<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;

/**
 * Maps a quote's `lineItems` association onto the bridge's read model.
 *
 * Everything here is shape; the gross→net conversion the read model needs
 * lives in QuoteLineNet, which explains why it is needed at all.
 */
final readonly class QuoteLineMapper
{
    public function __construct(
        private CommercialCapabilities $capabilities,
    ) {}

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
            if (!$lineItem instanceof Entity || $this->isRemoved($lineItem)) {
                continue;
            }

            $lines[] = $this->line($lineItem, QuoteLineNet::of($lineItem, $taxStatus, $this->capabilities));
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

    /**
     * A released SwagCommercial has no `deleted_at` on a quote line — removal
     * there is a real delete, so a row that is present is a live line and
     * `Entity::get()` would throw on the column rather than return null.
     */
    private function isRemoved(Entity $lineItem): bool
    {
        return $this->capabilities->softDeleteLines && $lineItem->get('deletedAt') !== null;
    }
}
