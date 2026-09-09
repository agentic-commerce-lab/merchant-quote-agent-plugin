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
 *
 * The one exception is `requestedUnitPrice`, which is subtracted when the
 * AGENT wrote it. This is the single read boundary every guard that treats
 * that field as buyer-only comes through, so hiding the mirror here is what
 * keeps all of them seeing what they saw before it existed — see MirroredAsks.
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

        $customFields = $quote->get('customFields');
        $mirrored = MirroredAsks::read(\is_array($customFields) ? $customFields : []);
        $taxStatus = (string) $quote->get('taxStatus');
        $lines = [];

        foreach ($lineItems as $lineItem) {
            if (!$lineItem instanceof Entity || $lineItem->get('deletedAt') !== null) {
                continue;
            }

            $lines[] = $this->line($lineItem, QuoteLineNet::of($lineItem, $taxStatus), $mirrored);
        }

        return $lines;
    }

    /** @param array<string, float> $mirrored */
    private function line(Entity $lineItem, QuoteLineNet $net, array $mirrored): QuoteLineSnapshot
    {
        $lineItemId = (string) $lineItem->get('id');

        return new QuoteLineSnapshot(
            identity: new QuoteLineIdentity(
                lineItemId: $lineItemId,
                label: $this->nullableString($lineItem->get('label')),
                productId: $this->nullableString($lineItem->get('referencedId')),
            ),
            quantity: (int) $lineItem->get('quantity'),
            unitPriceNet: $net->unitPrice,
            totalNet: $net->total,
            requestedUnitPrice: MirroredAsks::holds($mirrored, $lineItemId, $net->requestedUnitPrice)
                ? null
                : $net->requestedUnitPrice,
        );
    }

    private function nullableString(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }
}
