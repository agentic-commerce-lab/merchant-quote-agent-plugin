<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineItemChange;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

/**
 * Writes line-item changes through the generic DAL in one batched update.
 *
 * A repriced line MUST also carry customFields['quote_custom_offer_price'] =>
 * true. SwagCommercial's QuoteLineItemTransformer only adds the
 * ProductCartProcessor::CUSTOM_PRICE extension when that flag is present, and
 * without the extension the next recalculate() re-prices the line from the
 * catalog and silently discards the priceDefinition below.
 */
final readonly class QuoteLineItemWriter
{
    private const CUSTOM_PRICE_FLAG = 'quote_custom_offer_price';

    /** ponytail: 19% assumed, as the TS implementation did. Affects displayed
     * VAT only, not the net price the policy layer verifies. Echo the line's
     * real rate here if non-19% products matter. */
    private const ASSUMED_TAX_RATE = 19.0;

    /** @param EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $lineItemRepository */
    public function __construct(
        private EntityRepository $lineItemRepository,
    ) {}

    /** @param list<QuoteLineItemChange> $changes */
    public function write(array $changes, Context $context): void
    {
        $payload = [];

        foreach ($changes as $change) {
            $row = $this->rowFor($change);
            if ($row !== []) {
                $payload[] = ['id' => $change->lineItemId, ...$row];
            }
        }

        if ($payload !== []) {
            $this->lineItemRepository->update($payload, $context);
        }
    }

    /** @return array<string, mixed> */
    private function rowFor(QuoteLineItemChange $change): array
    {
        if ($change->isRemoval()) {
            // Soft delete, matching SwagCommercial's own model: the
            // quote-to-cart transformer and calculator skip deletedAt lines.
            return ['deletedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM)];
        }

        $row = [];

        if ($change->quantity !== null) {
            $row['quantity'] = $change->quantity;
        }

        if ($change->touchesPrice()) {
            $row += $this->priceRow($change);
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function priceRow(QuoteLineItemChange $change): array
    {
        return [
            'priceDefinition' => [
                'type' => 'quantity',
                'price' => $change->unitPriceNet,
                'quantity' => $change->quantity ?? 1,
                'isCalculated' => true,
                'taxRules' => [['taxRate' => self::ASSUMED_TAX_RATE, 'percentage' => 100]],
            ],
            'customFields' => [self::CUSTOM_PRICE_FLAG => true],
        ];
    }
}
