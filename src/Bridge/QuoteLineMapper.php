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
 *
 * The one exception is `requestedUnitPrice`, which is subtracted when the
 * AGENT wrote it. This is the single read boundary every guard that treats
 * that field as buyer-only comes through, so hiding the mirror here is what
 * keeps all of them seeing what they saw before it existed — see MirroredAsks.
 *
 * @mago-expect lint:cyclomatic-complexity
 * The rule aggregates per class (threshold 10); mapping the DAL entity's
 * nullable fields (label, referencedId, updatedAt/createdAt) and the mirrored
 * vs. buyer requestedUnitPrice each takes one null/type check.
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

        $customFields = $quote->get('customFields');
        $mirrored = MirroredAsks::read(\is_array($customFields) ? $customFields : []);
        $taxStatus = (string) $quote->get('taxStatus');
        $lines = [];

        foreach ($lineItems as $lineItem) {
            if (!$lineItem instanceof Entity || $this->isRemoved($lineItem)) {
                continue;
            }

            $lines[] = $this->line($lineItem, QuoteLineNet::of($lineItem, $taxStatus, $this->capabilities), $mirrored);
        }

        return $lines;
    }

    /** @param array<string, float> $mirrored */
    private function line(Entity $lineItem, QuoteLineNet $net, array $mirrored): QuoteLineSnapshot
    {
        $lineItemId = (string) $lineItem->get('id');
        $updatedAt = $lineItem->get('updatedAt') ?? $lineItem->get('createdAt');

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
            netRatio: $net->netRatio,
            updatedAt: $updatedAt instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($updatedAt)
                : null,
            totalInQuotePriceSpace: (float) $lineItem->get('totalPrice'),
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
