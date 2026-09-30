<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability;
use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
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
 * registered when SwagCommercial's quote bundle is (see CommercialAvailability).
 *
 * @mago-expect lint:too-many-methods
 * Seven interface methods plus the snapshot/load pair and the routes-wired
 * check is what six Store API operations take once the preconditions move to
 * CommercialQuoteAccess. Tracked with the rest of the gateway's shape in #44.
 *
 * @mago-expect lint:cyclomatic-complexity
 * The rule aggregates per class (threshold 10) and every branch here is real:
 * requestQuote() rejects a cart left empty by both the request and the
 * additions it landed, and branches on `$capabilities->draftBeforeSend` for
 * the send step, counterQuote() only touches pricing when line items were
 * actually sent, loadQuote() translates any commercial exception into
 * not-found so a foreign quote is indistinguishable from a missing one, and
 * hasCommercialRoutes() checks all six. None of that is incidental
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
     * service on a shop without the commercial backend. Of the rest,
     * `$access`, `$snapshotMapper` and `$linePricing` are the gateway's own
     * collaborators, `$cartService` and `$lineItemFactory` are core services
     * `requestQuote()` needs to fill the cart before any commercial route
     * runs, and `$capabilities` is the runtime-probed
     * `CommercialCapabilities` this whole class branches on (`draftBeforeSend`
     * in `requestQuote()`, and every read consumer downstream of it) — thirteen
     * in total. Bundling the routes into a value object moves the same arity
     * one file over; the shape is tracked in #44.
     */
    public function __construct(
        private readonly CommercialQuoteAccess $access,
        private readonly CommercialQuoteSnapshotMapper $snapshotMapper,
        private readonly CommercialQuoteLinePricing $linePricing,
        private readonly CartService $cartService,
        private readonly LineItemFactoryRegistry $lineItemFactory,
        private readonly CommercialCapabilities $capabilities,
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
        return $this->hasCommercialRoutes() && $this->access->isAvailable() && CommercialAvailability::isLicensed();
    }

    #[Override]
    public function requestQuote(SalesChannelContext $context, array $lineItems, ?string $comment): QuoteSnapshot
    {
        $this->access->assertServable();
        $customerId = $this->access->requireCustomerId($context);
        $this->access->assertCustomerHasQuoteFeature($customerId);

        $lines = RequestedQuoteLines::from($lineItems, $this->linePricing);
        $requestedPrices = $lines->requestedPrices;

        /** @var list<LineItem> $items */
        $items = [];
        foreach ($lines->additions as $addition) {
            $items[] = $this->lineItemFactory->create([
                'type' => LineItem::PRODUCT_LINE_ITEM_TYPE,
                'referencedId' => $addition['product_id'],
                'quantity' => $addition['quantity'],
            ], $context);
        }

        // Deliberately through CartService rather than the item-add route directly:
        // the commercial quote route reads the cart back through CartService, which
        // caches per context token, so a cart filled around it would look empty to
        // the quote. CartService itself delegates to the Store API item-add route,
        // so the route boundary is still respected.
        $cart = $this->cartService->getCart($context->getToken(), $context);
        if ([] !== $items) {
            $this->cartService->add($cart, $items, $context);
        }

        // The emptiness check moved here from the head of the method, because
        // "did the buyer ask for anything?" is only answerable once the
        // additions have landed. An assistant-sent request adds nothing and
        // quotes a cart the buyer filled through the starter kit's
        // `add_to_cart`; a UCP request adds everything and its old
        // empty-array rejection now fails one step later, identically.
        if (0 === $cart->getLineItems()->count()) {
            throw new ValidationException('A quote request needs a line item or a cart.', [
                '$.line_items must not be empty when the cart is empty',
            ]);
        }

        // A released SwagCommercial's request() has no second, send-stage
        // comment call — the comment must ride along with the draft itself, or
        // it is lost. Trunk splits create-draft from send, so passing it here
        // too would post it twice; only the legacy path carries it here.
        $requestBag = $this->capabilities->draftBeforeSend
            ? null
            : new RequestDataBag(['comment' => trim($comment ?? '')]);

        /**
         * The route returns an untyped SwagCommercial entity — no type to
         * narrow to; BuyerQuoteFlowTest proves the call is correct against
         * a real shop.
         *
         * @mago-expect analysis:mixed-method-access
         */
        $quote = CommercialQuoteAccess::service($this->quoteRequestRoute, 'quote request')
            ->request($context, $requestBag)
            ->getQuote();
        /** @mago-expect analysis:mixed-method-access */
        $quoteId = (string) $quote->getId();

        /** @mago-expect analysis:mixed-argument */
        $this->linePricing->applyRequestedPrices($quoteId, $quote, $requestedPrices, $context);

        if ($this->capabilities->draftBeforeSend) {
            CommercialQuoteAccess::service($this->quoteSendRequestRoute, 'quote send-request')->sendRequest(
                $context,
                $quoteId,
                new RequestDataBag(['comment' => trim($comment ?? '')]),
            );
        }

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
        $criteria->addAssociation('currency'); // the listing route only associates this itself
        $this->addQuoteReadAssociations($criteria);

        /**
         * The route returns an untyped SwagCommercial collection — no type
         * to narrow to; BuyerQuoteFlowTest proves the listing is correct
         * against a real shop.
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

        $quote = $this->loadQuote($quoteId, $context);

        if ([] !== $lineItems) {
            $this->linePricing->assertCanPriceLines();
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
        $this->loadQuote($quoteId, $context);

        /**
         * The route returns an untyped SwagCommercial order — no type to
         * narrow to; BuyerQuoteFlowTest proves the order is correct against
         * a real shop.
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
        return $snapshot->withOrder((string) $order->getId(), $order->getOrderNumber());
    }

    #[Override]
    public function declineQuote(SalesChannelContext $context, string $quoteId, ?string $comment): QuoteSnapshot
    {
        $this->access->assertServable();
        $customerId = $this->access->requireCustomerId($context);
        $this->access->assertCustomerHasQuoteFeature($customerId);
        $this->loadQuote($quoteId, $context);

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
     * The six routes every supported SwagCommercial has. `quoteSendRequestRoute`
     * is deliberately absent: trunk splits a quote request into create-draft
     * plus send, a released SwagCommercial creates the quote in `open` in one
     * call, so the route missing means the step does not exist — not that the
     * gateway is broken. `requestQuote()` branches on
     * `$capabilities->draftBeforeSend` instead.
     */
    private function hasCommercialRoutes(): bool
    {
        return (
            null !== $this->quoteRequestRoute
            && null !== $this->quoteLoadRoute
            && null !== $this->quoteListingRoute
            && null !== $this->quoteRequestChangeRoute
            && null !== $this->quoteDeclineRoute
            && null !== $this->quoteOrderRoute
        );
    }

    /**
     * `quote_line_item.deleted_at` is a trunk-only column: released
     * SwagCommercial (6.7.1.2–6.7.12.x) never added it, and the DAL rejects a
     * criteria that names an unmapped field outright — `UnmappedFieldException`,
     * not a silently-ignored filter — so adding it unconditionally breaks every
     * read on a released shop. Gated on `$capabilities->softDeleteLines`
     * instead: where the column does not exist, removal is a hard delete, so
     * the `lineItems` association can never contain a soft-deleted row and
     * there is nothing for the filter to exclude.
     */
    private function excludeSoftDeletedLineItems(Criteria $criteria): void
    {
        if (!$this->capabilities->softDeleteLines) {
            return;
        }

        $criteria->getAssociation('lineItems')->addFilter(new EqualsFilter('deletedAt', null));
    }

    /** Shared by listQuotes() and loadQuote(): both read state, line items and comments off the quote entity. */
    private function addQuoteReadAssociations(Criteria $criteria): void
    {
        $criteria->addAssociation('stateMachineState'); // unconditional: trunk's routes associate this themselves, released SwagCommercial does not - relying on the route would mean relying on that version difference
        $criteria->addAssociation('lineItems');
        $this->excludeSoftDeletedLineItems($criteria);
        $criteria->addAssociation('comments');
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
        $this->addQuoteReadAssociations($criteria);
        $quoteLoadRoute = CommercialQuoteAccess::service($this->quoteLoadRoute, 'quote load');

        try {
            /**
             * The route returns an untyped SwagCommercial entity — no type
             * to narrow to; BuyerQuoteFlowTest proves the load is correct
             * against a real shop.
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
