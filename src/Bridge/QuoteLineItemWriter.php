<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
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
 *
 * `QuoteLineItemChange::unitPriceNet` is a NET price, and `isCalculated =>
 * false` is what makes it one. With it true, GrossPriceCalculator::getUnitPrice()
 * short-circuits and stores the number verbatim into `price.unitPrice` — a
 * gross field. False makes the calculator convert: calculateGross() in a gross
 * quote, straight through in a net one, so nothing here branches on tax mode.
 *
 * Removal is the one operation that differs by SwagCommercial version. Trunk
 * added `quote_line_item.deleted_at` and its transformer skips those rows; a
 * released SwagCommercial has no such column, and the DAL rejects an unknown
 * field outright rather than ignoring it — so there, removal is a real delete.
 * That loses the audit trail the soft-delete model preserves, which is accepted:
 * a released shop has nowhere to preserve it, and refusing to remove lines would
 * block a concession the agent is otherwise authorized to make. The A2CN act
 * chain lives in `quote.customFields` and is unaffected either way.
 *
 * @mago-expect lint:cyclomatic-complexity
 * The rule aggregates per class (threshold 10); the branch splitting removals
 * from updates in `write()` pushed this over it. Each branch corresponds to a
 * genuinely different DAL operation (update vs. delete) or payload shape
 * (quantity vs. price vs. removal), so splitting further would move the count
 * around rather than remove it.
 */
final readonly class QuoteLineItemWriter
{
    private const CUSTOM_PRICE_FLAG = 'quote_custom_offer_price';

    private QuoteLineTaxRules $taxRules;

    /** @param EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $lineItemRepository */
    public function __construct(
        private EntityRepository $lineItemRepository,
        private CommercialCapabilities $capabilities,
    ) {
        $this->taxRules = new QuoteLineTaxRules($lineItemRepository, $capabilities);
    }

    /** @param list<QuoteLineItemChange> $changes */
    public function write(array $changes, Context $context): void
    {
        // One id list for both reads. Narrowing each to only the lines that
        // need it saved a query on a quantity-only batch and cost this class
        // its complexity budget; both readers no-op on an empty list, and a
        // line that needs neither fact is simply not looked up.
        $priced = self::pricedIds($changes);
        $taxRules = $this->taxRules->forLines($priced, $context);
        $netRatios = $this->taxRules->netRatiosFor($priced, $context);
        $payload = [];
        $deletions = [];

        foreach ($changes as $change) {
            if ($change->isRemoval() && !$this->capabilities->softDeleteLines) {
                $deletions[] = ['id' => $change->lineItemId];

                continue;
            }

            $row = $this->rowFor($change, $taxRules, $netRatios);
            if ($row !== []) {
                $payload[] = ['id' => $change->lineItemId, ...$row];
            }
        }

        if ($payload !== []) {
            $this->lineItemRepository->update($payload, $context);
        }

        if ($deletions !== []) {
            $this->lineItemRepository->delete($deletions, $context);
        }
    }

    /**
     * The lines carrying a price of either kind — the quoted one or the
     * buyer's ask. Both need a fact the database holds: the tax rules a
     * reprice repeats, and the ratio an ask is converted back through.
     *
     * @param list<QuoteLineItemChange> $changes
     *
     * @return list<string>
     */
    private static function pricedIds(array $changes): array
    {
        return array_values(array_map(
            static fn(QuoteLineItemChange $change): string => $change->lineItemId,
            array_filter(
                $changes,
                static fn(QuoteLineItemChange $c): bool => $c->touchesPrice() || $c->touchesRequestedPrice(),
            ),
        ));
    }

    /**
     * @param array<string, list<array{taxRate: float, percentage: float}>> $taxRules
     * @param array<string, float> $netRatios
     *
     * @return array<string, mixed>
     */
    private function rowFor(QuoteLineItemChange $change, array $taxRules, array $netRatios): array
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
            $row += $this->priceRow($change, $taxRules[$change->lineItemId] ?? QuoteLineTaxRules::FALLBACK);
        }

        $ratio = $netRatios[$change->lineItemId] ?? null;

        return $row + QuoteLineTaxRules::requestedPriceRow($change->requestedUnitPriceNet, $ratio);
    }

    /**
     * @param list<array{taxRate: float, percentage: float}> $taxRules
     *
     * @return array<string, mixed>
     */
    private function priceRow(QuoteLineItemChange $change, array $taxRules): array
    {
        return [
            'priceDefinition' => [
                'type' => 'quantity',
                'price' => $change->unitPriceNet,
                // Overwritten from the line item's own quantity by
                // ProductCartProcessor::process() before calculation.
                'quantity' => $change->quantity ?? 1,
                'isCalculated' => false,
                'taxRules' => $taxRules,
            ],
            'customFields' => [self::CUSTOM_PRICE_FLAG => true],
        ];
    }
}
