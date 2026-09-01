<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Ucp\Sdk\Exception\UnsupportedCapabilityException;
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
 * Both apply*Prices() methods are a loop with a per-item conditional over an
 * existing quote's line items — that branching IS the mapping from a
 * requested unit price to the matching line item, not incidental complexity
 * to design away. Tracked with the rest of the gateway's shape in #44.
 *
 * @mago-expect analysis:mixed-method-access
 * @mago-expect analysis:invalid-iterator
 * @mago-expect analysis:possibly-invalid-argument
 * Every line item here is read off an untyped SwagCommercial entity — the
 * same soft-dependency seam SwagCommercialProductAdder suppresses at one
 * call. There is no SwagCommercial type to narrow to; BuyerQuoteFlowTest
 * proves the writes are correct against the live shop.
 */
final class CommercialQuoteLinePricing
{
    public function __construct(
        private readonly ?object $quoteLineItemRoute = null,
    ) {}

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

        foreach ($quote->getLineItems() ?? [] as $lineItem) {
            $price = $requestedPrices[$lineItem->getProductId()] ?? null;
            if (null === $price) {
                continue;
            }

            $this->route()->edit($quoteId, (string) $lineItem->getId(), $context, new RequestDataBag([
                'requestedPrice' => $price,
            ]));
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

        foreach ($quote->getLineItems() ?? [] as $lineItem) {
            $byId[(string) $lineItem->getId()] = $lineItem;
            $productId = $lineItem->getProductId();
            if (null !== $productId) {
                $byProductId[$productId] = $lineItem;
            }
        }

        foreach ($lineItems as $index => $lineItem) {
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

            $this->route()->edit($quoteId, (string) $target->getId(), $context, new RequestDataBag([
                'requestedPrice' => $price,
            ]));
        }
    }

    /**
     * `$quoteLineItemRoute` is nullable so the plugin degrades service by
     * service; this is where a missing one surfaces as a legible exception
     * instead of a fatal error on a null method call.
     */
    private function route(): object
    {
        if (null === $this->quoteLineItemRoute) {
            throw new UnsupportedCapabilityException('The commercial quote line-item route is unavailable.');
        }

        return $this->quoteLineItemRoute;
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
