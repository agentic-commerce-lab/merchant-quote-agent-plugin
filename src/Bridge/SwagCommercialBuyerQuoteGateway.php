<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteList;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteSnapshot;
use Override;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItemFactoryRegistry;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Ucp\Sdk\Exception\ResourceNotFoundException;
use Ucp\Sdk\Exception\ValidationException;

/**
 * Buyer-facing quote flows on top of the commercial B2B Store API routes.
 *
 * The commercial plugin stays authoritative for all quote logic (pricing, state
 * machine, ownership): every operation calls its Store API routes in the given
 * customer's sales-channel context, so contract prices and rules behave exactly
 * as if the customer acted themselves. The routes are typed as `object` because
 * SwagCommercial is a runtime-detected soft dependency — this service is only
 * registered when its classes exist (see CommercialAvailability).
 *
 * @mago-expect lint:too-many-methods
 * Seven interface methods plus the snapshot/load pair and the routes-wired
 * check is what six Store API operations take once the preconditions move to
 * CommercialQuoteAccess. Tracked with the rest of the gateway's shape in #44.
 *
 * @mago-expect lint:cyclomatic-complexity
 * The rule aggregates per class (threshold 10) and every branch here is real:
 * requestQuote() rejects an empty line-item list and a non-positive quantity
 * inline (both 422s), counterQuote() only touches pricing when line items were
 * actually sent, loadQuote() translates any commercial exception into
 * not-found so a foreign quote is indistinguishable from a missing one, and
 * hasCommercialRoutes() checks all seven. None of that is incidental
 * complexity; it's the port's error handling. Splitting further would
 * redistribute the count without drawing a boundary worth having — the
 * gateway's shape is tracked in #44.
 */
final class SwagCommercialBuyerQuoteGateway implements BuyerQuoteGatewayInterface
{
    /** Upper bound on a listing page, so an agent cannot ask for the whole table. */
    private const MAX_LIST_LIMIT = 50;

    /**
     * @mago-expect lint:excessive-parameter-list
     * Seven of these are individually `nullOnInvalid()` references to
     * SwagCommercial routes — ADR 0001 requires them to stay individually
     * typed and individually null-checkable, so the plugin degrades service by
     * service on a shop without the commercial backend. Bundling them into a
     * value object moves the same arity one file over; the shape is tracked
     * in #44.
     */
    public function __construct(
        private readonly CommercialQuoteAccess $access,
        private readonly CommercialQuoteSnapshotMapper $snapshotMapper,
        private readonly CommercialQuoteLinePricing $linePricing,
        private readonly CartService $cartService,
        private readonly LineItemFactoryRegistry $lineItemFactory,
        private readonly ?object $quoteRequestRoute = null,
        private readonly ?object $quoteSendRequestRoute = null,
        private readonly ?object $quoteLoadRoute = null,
        private readonly ?object $quoteListingRoute = null,
        private readonly ?object $quoteRequestChangeRoute = null,
        private readonly ?object $quoteDeclineRoute = null,
        private readonly ?object $quoteOrderRoute = null,
    ) {}

    #[Override]
    public function isAvailable(): bool
    {
        return (
            $this->hasCommercialRoutes()
            && $this->linePricing->isAvailable()
            && CommercialAvailability::isLicensed()
        );
    }

    #[Override]
    public function requestQuote(SalesChannelContext $context, array $lineItems, ?string $comment): QuoteSnapshot
    {
        $this->access->assertServable();
        $customerId = $this->access->requireCustomerId($context);
        $this->access->assertCustomerHasQuoteFeature($customerId);

        if ([] === $lineItems) {
            throw new ValidationException('A quote request needs at least one line item.', [
                '$.line_items must not be empty',
            ]);
        }

        $requestedPrices = [];
        /** @var list<LineItem> $items */
        $items = [];

        foreach ($lineItems as $index => $lineItem) {
            /**
             * The agent-supplied line item may carry `quantity`/
             * `requested_unit_price` too; `requireProductId()` only reads
             * `product_id`/`product_number`, so the extra keys are why the
             * parameter type looks like a mismatch.
             *
             * @mago-expect analysis:possibly-invalid-argument
             */
            $productId = $this->linePricing->requireProductId($lineItem, $index);
            $quantity = $lineItem['quantity'] ?? null;

            if (!\is_int($quantity) || $quantity < 1) {
                throw new ValidationException('Line item quantity must be a positive integer.', [\sprintf(
                    '$.line_items[%d].quantity must be >= 1',
                    $index,
                )]);
            }

            $items[] = $this->lineItemFactory->create([
                'type' => LineItem::PRODUCT_LINE_ITEM_TYPE,
                'referencedId' => $productId,
                'quantity' => $quantity,
            ], $context);

            /** @mago-expect analysis:possibly-invalid-argument */
            $requestedPrice = $this->linePricing->requestedPrice($lineItem, \sprintf(
                '$.line_items[%d].requested_unit_price',
                $index,
            ));
            if (null !== $requestedPrice) {
                $requestedPrices[$productId] = $requestedPrice;
            }
        }

        // Deliberately through CartService rather than the item-add route directly:
        // the commercial quote route reads the cart back through CartService, which
        // caches per context token, so a cart filled around it would look empty to
        // the quote. CartService itself delegates to the Store API item-add route,
        // so the route boundary is still respected.
        $cart = $this->cartService->getCart($context->getToken(), $context);
        $this->cartService->add($cart, $items, $context);

        /**
         * The route returns an untyped SwagCommercial entity — no type to
         * narrow to; BuyerQuoteFlowTest proves the call is correct against
         * the live shop.
         *
         * @mago-expect analysis:mixed-method-access
         */
        $quote = CommercialQuoteAccess::service($this->quoteRequestRoute, 'quote request')
            ->request($context)
            ->getQuote();
        /** @mago-expect analysis:mixed-method-access */
        $quoteId = (string) $quote->getId();

        /** @mago-expect analysis:mixed-argument */
        $this->linePricing->applyRequestedPrices($quoteId, $quote, $requestedPrices, $context);

        CommercialQuoteAccess::service($this->quoteSendRequestRoute, 'quote send-request')->sendRequest(
            $context,
            $quoteId,
            new RequestDataBag([
                'comment' => trim($comment ?? ''),
            ]),
        );

        return $this->loadSnapshot($quoteId, $context);
    }

    #[Override]
    public function getQuote(SalesChannelContext $context, string $quoteId): QuoteSnapshot
    {
        $this->access->assertServable();
        $this->access->requireCustomerId($context);

        return $this->loadSnapshot($quoteId, $context);
    }

    #[Override]
    public function listQuotes(SalesChannelContext $context, int $limit, int $page): QuoteList
    {
        $this->access->assertServable();
        $this->access->requireCustomerId($context);

        $limit = max(1, min($limit, self::MAX_LIST_LIMIT));
        $page = max(1, $page);

        $criteria = new Criteria();
        $criteria->setLimit($limit);
        $criteria->setOffset(($page - 1) * $limit);
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
        // The listing route only associates the currency, so state and line items
        // must be requested here or every entry would come back stateless - and
        // state is exactly what an agent polls this endpoint for.
        $criteria->addAssociation('stateMachineState');
        $criteria->addAssociation('currency');
        $criteria->addAssociation('lineItems');
        $criteria->getAssociation('lineItems')->addFilter(new EqualsFilter('deletedAt', null));
        $criteria->addAssociation('comments');

        /**
         * The route returns an untyped SwagCommercial collection — no type
         * to narrow to; BuyerQuoteFlowTest proves the listing is correct
         * against the live shop.
         *
         * @mago-expect analysis:mixed-method-access
         */
        $result = CommercialQuoteAccess::service($this->quoteListingRoute, 'quote listing')
            ->quotes($context, new Request(), $criteria)
            ->getQuotes();

        $quotes = [];
        /**
         * @mago-expect analysis:invalid-iterator
         * @mago-expect analysis:mixed-argument
         */
        foreach ($result as $quote) {
            $quotes[] = $this->snapshotMapper->toSnapshot($quote);
        }

        /**
         * @mago-expect analysis:mixed-method-access
         * @mago-expect analysis:mixed-argument
         */
        return new QuoteList($quotes, $result->getTotal(), $limit, $page);
    }

    #[Override]
    public function counterQuote(
        SalesChannelContext $context,
        string $quoteId,
        array $lineItems,
        ?string $comment,
    ): QuoteSnapshot {
        $this->access->assertServable();
        $customerId = $this->access->requireCustomerId($context);
        $this->access->assertCustomerHasQuoteFeature($customerId);

        if ([] !== $lineItems) {
            $quote = $this->loadQuote($quoteId, $context);
            $this->linePricing->applyCounterPrices($quoteId, $quote, $lineItems, $context);
        }

        CommercialQuoteAccess::service($this->quoteRequestChangeRoute, 'quote request-change')->requestChange(
            $context,
            $quoteId,
            new RequestDataBag([
                'comment' => trim($comment ?? ''),
            ]),
        );

        return $this->loadSnapshot($quoteId, $context);
    }

    #[Override]
    public function acceptQuote(SalesChannelContext $context, string $quoteId): QuoteSnapshot
    {
        $this->access->assertServable();
        $customerId = $this->access->requireCustomerId($context);
        $this->access->assertCustomerHasQuoteFeature($customerId);

        /**
         * The route returns an untyped SwagCommercial order — no type to
         * narrow to; BuyerQuoteFlowTest proves the order is correct against
         * the live shop.
         *
         * @mago-expect analysis:mixed-method-access
         */
        $order = CommercialQuoteAccess::service($this->quoteOrderRoute, 'quote order')
            ->order($context, new RequestDataBag(), $quoteId)
            ->getOrder();
        $snapshot = $this->loadSnapshot($quoteId, $context);

        /**
         * @mago-expect analysis:mixed-method-access
         * @mago-expect analysis:mixed-method-access
         * @mago-expect analysis:mixed-argument
         */
        return new QuoteSnapshot(
            id: $snapshot->id,
            quoteNumber: $snapshot->quoteNumber,
            state: $snapshot->state,
            expirationDate: $snapshot->expirationDate,
            currency: $snapshot->currency,
            totalGross: $snapshot->totalGross,
            totalNet: $snapshot->totalNet,
            taxStatus: $snapshot->taxStatus,
            lineItems: $snapshot->lineItems,
            comments: $snapshot->comments,
            orderId: (string) $order->getId(),
            orderNumber: $order->getOrderNumber(),
        );
    }

    #[Override]
    public function declineQuote(SalesChannelContext $context, string $quoteId, ?string $comment): QuoteSnapshot
    {
        $this->access->assertServable();
        $customerId = $this->access->requireCustomerId($context);
        $this->access->assertCustomerHasQuoteFeature($customerId);

        CommercialQuoteAccess::service($this->quoteDeclineRoute, 'quote decline')->decline(
            $context,
            $quoteId,
            new RequestDataBag(['comment' => trim($comment ?? '')]),
        );

        return $this->loadSnapshot($quoteId, $context);
    }

    private function loadSnapshot(string $quoteId, SalesChannelContext $context): QuoteSnapshot
    {
        return $this->snapshotMapper->toSnapshot($this->loadQuote($quoteId, $context));
    }

    /**
     * Whether the seven buyer-side routes actually resolved. They are
     * ignore-on-invalid references, so on a shop without SwagCommercial — or
     * with it installed but missing one of these specific routes — some
     * arrive null and quoting must not be advertised as available.
     */
    private function hasCommercialRoutes(): bool
    {
        return (
            null !== $this->quoteRequestRoute
            && null !== $this->quoteSendRequestRoute
            && null !== $this->quoteLoadRoute
            && null !== $this->quoteListingRoute
            && null !== $this->quoteRequestChangeRoute
            && null !== $this->quoteDeclineRoute
            && null !== $this->quoteOrderRoute
        );
    }

    /**
     * The commercial load route filters by customer and sales channel, so a quote
     * belonging to somebody else is indistinguishable from one that does not
     * exist - its exception is translated to a not-found without confirming
     * existence.
     */
    private function loadQuote(string $quoteId, SalesChannelContext $context): object
    {
        $criteria = new Criteria();
        $criteria->addAssociation('lineItems');
        $criteria->getAssociation('lineItems')->addFilter(new EqualsFilter('deletedAt', null));
        $criteria->addAssociation('comments');

        $quoteLoadRoute = CommercialQuoteAccess::service($this->quoteLoadRoute, 'quote load');

        try {
            /**
             * The route returns an untyped SwagCommercial entity — no type
             * to narrow to; BuyerQuoteFlowTest proves the load is correct
             * against the live shop.
             *
             * @mago-expect analysis:mixed-method-access
             */
            $quote = $quoteLoadRoute->load($quoteId, $context, $criteria)->getQuote();
        } catch (\Exception $exception) {
            throw new ResourceNotFoundException(
                \sprintf('Quote "%s" was not found for this customer.', $quoteId),
                previous: $exception,
            );
        }

        /** @mago-expect analysis:mixed-return-statement */
        return $quote;
    }
}
