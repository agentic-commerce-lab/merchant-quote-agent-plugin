<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Ucp\Sdk\Exception\ValidationException;

/**
 * Turns an agent's requested per-unit prices into line-item writes against a
 * quote.
 *
 * Separate from the gateway for the same reason CommercialQuoteSnapshotMapper
 * is: a different job. The gateway decides which commercial route to call and
 * in which order; this decides what a "requested_unit_price" means for the
 * lines of an existing quote, which is where nearly all of the branching lives.
 *
 * @mago-expect lint:cyclomatic-complexity
 * The rule aggregates per class (threshold 10) and every branch here is a real
 * case in agent-supplied input: a line with no requested price is skipped, a
 * counter line matching neither line id nor product id is a 422, a non-numeric
 * price is a 422, and quote lines are indexed both ways because an agent may
 * address a line either way. Splitting further would redistribute the count
 * without drawing a boundary worth having; the gateway's shape is tracked in #44.
 */
final readonly class CommercialQuoteLinePricing
{
    public function __construct(
        private ?object $quoteLineItemRoute = null,
    ) {}

    /** The one commercial route this class needs. */
    public function isAvailable(): bool
    {
        return null !== $this->quoteLineItemRoute;
    }

    /**
     * Buyer asks belong in `requestedPrice` (per unit) - never in a price
     * definition, which newer commercial versions rebuild from the catalog.
     *
     * @param array<string, float> $requestedPrices product id => requested unit price
     */
    public function applyRequestedPrices(
        string $quoteId,
        object $quote,
        array $requestedPrices,
        SalesChannelContext $context,
    ): void {
        if ([] === $requestedPrices) {
            return;
        }

        /** @mago-expect analysis:invalid-iterator */
        /** @mago-expect analysis:mixed-method-access */
        foreach ($quote->getLineItems() ?? [] as $lineItem) {
            $productId = $lineItem->getProductId();

            // array_key_exists rather than ?? null: mago can see $requestedPrices
            // is array<string, float>, so a lookup can never itself be null - the
            // thing that can be missing is the key. Saying that explicitly is what
            // a null-coalesce here would only assert wrongly.
            if (!\is_string($productId) || !\array_key_exists($productId, $requestedPrices)) {
                continue;
            }

            /** @mago-expect analysis:mixed-method-access */
            CommercialQuoteAccess::service($this->quoteLineItemRoute, 'quote line-item')->edit(
                $quoteId,
                (string) $lineItem->getId(),
                $context,
                new RequestDataBag(['requestedPrice' => $requestedPrices[$productId]]),
            );
        }
    }

    /**
     * @param list<array{id?: string, product_id?: string, requested_unit_price?: float|int|string}> $lineItems
     */
    public function applyCounterPrices(
        string $quoteId,
        object $quote,
        array $lineItems,
        SalesChannelContext $context,
    ): void {
        $byId = [];
        $byProductId = [];

        /** @mago-expect analysis:invalid-iterator */
        /** @mago-expect analysis:mixed-method-access */
        foreach ($quote->getLineItems() ?? [] as $lineItem) {
            $byId[(string) $lineItem->getId()] = $lineItem;
            /** @mago-expect analysis:mixed-method-access */
            $productId = $lineItem->getProductId();
            if (null !== $productId) {
                $byProductId[$productId] = $lineItem;
            }
        }

        foreach ($lineItems as $index => $lineItem) {
            /**
             * The wider `$lineItems` shape (`id`, `product_id`,
             * `requested_unit_price`) always has the one field
             * `requestedPrice()` reads; the extra keys are why the parameter
             * type looks like a mismatch.
             *
             * @mago-expect analysis:possibly-invalid-argument
             */
            $price = $this->requestedPrice($lineItem, \sprintf('$.line_items[%d].requested_unit_price', $index));
            if (null === $price) {
                continue;
            }

            $target = $byId[$lineItem['id'] ?? ''] ?? $byProductId[$lineItem['product_id'] ?? ''] ?? null;
            if (null === $target) {
                throw new ValidationException('Counter-offer line item does not match any quote line item.', [\sprintf(
                    '$.line_items[%d] must reference an existing quote line item id or product id',
                    $index,
                )]);
            }

            /** @mago-expect analysis:mixed-method-access */
            CommercialQuoteAccess::service($this->quoteLineItemRoute, 'quote line-item')->edit(
                $quoteId,
                (string) $target->getId(),
                $context,
                new RequestDataBag(['requestedPrice' => $price]),
            );
        }
    }

    /**
     * @param array{product_id?: string, product_number?: string} $lineItem
     */
    public function requireProductId(array $lineItem, int $index): string
    {
        $productId = $lineItem['product_id'] ?? null;
        if (\is_string($productId) && '' !== $productId) {
            return $productId;
        }

        // Resolving a product number would need a catalog lookup; agents get the id
        // from the catalog capability, so v1 requires it explicitly.
        throw new ValidationException('Each quote line item needs a product id.', [\sprintf(
            '$.line_items[%d].product_id is required',
            $index,
        )]);
    }

    /**
     * @param array{requested_unit_price?: float|int|string} $lineItem
     */
    public function requestedPrice(array $lineItem, string $path): ?float
    {
        $requestedPrice = $lineItem['requested_unit_price'] ?? null;
        if (null === $requestedPrice || '' === $requestedPrice) {
            return null;
        }

        if (!is_numeric($requestedPrice)) {
            throw new ValidationException('Requested unit price must be numeric.', [$path . ' must be a number']);
        }

        return (float) $requestedPrice;
    }
}
