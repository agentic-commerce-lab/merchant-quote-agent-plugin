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
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Ucp\Sdk\Exception\ResourceNotFoundException;
use Ucp\Sdk\Exception\UnsupportedCapabilityException;
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
 * Seven interface methods plus four private helpers (the quote-feature check,
 * the snapshot/load pair, and the null-route guard added to satisfy Mago's own
 * analyzer — see that method's docblock) is what six Store API operations and
 * their shared error handling actually take. Tracked with the rest of the
 * gateway's shape in #44.
 *
 * @mago-expect lint:cyclomatic-complexity
 * The rule aggregates per class (threshold 10) and every branch here is real:
 * requestQuote() rejects an empty line-item list and a non-positive quantity
 * inline (both 422s), counterQuote() only touches pricing when line items were
 * actually sent, loadQuote() translates any commercial exception into
 * not-found so a foreign quote is indistinguishable from a missing one, and
 * every route access goes through a null-check that turns a missing service
 * into a named UnsupportedCapabilityException instead of a fatal error. None
 * of that is incidental complexity; it's the port's error handling. Splitting
 * further would redistribute the count without drawing a boundary worth
 * having — the gateway's shape is tracked in #44.
 *
 * @mago-expect analysis:mixed-method-access
 * @mago-expect analysis:mixed-argument
 * @mago-expect analysis:invalid-iterator
 * @mago-expect analysis:possibly-invalid-argument
 * @mago-expect analysis:mixed-return-statement
 * Every route call returns an untyped SwagCommercial entity — the same
 * soft-dependency seam SwagCommercialProductAdder suppresses at one call,
 * scaled up over six operations. There is no SwagCommercial type to narrow
 * to; BuyerQuoteFlowTest proves the calls are correct against the live shop.
 */
final class SwagCommercialBuyerQuoteGateway implements BuyerQuoteGatewayInterface
{
    /** Feature key in `customer_specific_features` (a map, not a list). */
    private const CUSTOMER_FEATURE = 'QUOTE_MANAGEMENT';

    /** Upper bound on a listing page, so an agent cannot ask for the whole table. */
    private const MAX_LIST_LIMIT = 50;

    /**
     * @mago-expect lint:excessive-parameter-list
     * Nine of these are individually `nullOnInvalid()` references to
     * SwagCommercial services — ADR 0001 requires them to stay individually
     * typed and individually null-checkable, so the plugin degrades service by
     * service on a shop without the commercial backend. Bundling them into a
     * value object moves the same arity one file over; the shape is tracked
     * in #44.
     *
     * @param EntityRepository<EntityCollection<Entity>> $quoteRepository the `quote` entity repository - typed on the
     *                                                                    base EntityCollection because the concrete
     *                                                                    QuoteCollection class lives in SwagCommercial,
     *                                                                    the same runtime-detected soft dependency as
     *                                                                    the routes below
     */
    public function __construct(
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
        private readonly ?object $customerSpecificFeatureService = null,
        private readonly ?EntityRepository $quoteRepository = null,
    ) {}

    #[Override]
    public function isAvailable(): bool
    {
        return CommercialAvailability::isAvailableByClass() && CommercialAvailability::isLicensed();
    }

    #[Override]
    public function requestQuote(SalesChannelContext $context, array $lineItems, ?string $comment): QuoteSnapshot
    {
        $this->assertCustomerHasQuoteFeature($context);

        if ([] === $lineItems) {
            throw new ValidationException('A quote request needs at least one line item.', [
                '$.line_items must not be empty',
            ]);
        }

        $requestedPrices = [];
        /** @var list<LineItem> $items */
        $items = [];

        foreach ($lineItems as $index => $lineItem) {
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

        $quote = $this->route($this->quoteRequestRoute, 'quote request')->request($context)->getQuote();
        $quoteId = (string) $quote->getId();

        $this->linePricing->applyRequestedPrices($quoteId, $quote, $requestedPrices, $context);

        $this->route($this->quoteSendRequestRoute, 'quote send-request')->sendRequest(
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
        return $this->loadSnapshot($quoteId, $context);
    }

    #[Override]
    public function listQuotes(SalesChannelContext $context, int $limit, int $page): QuoteList
    {
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

        $result = $this
            ->route($this->quoteListingRoute, 'quote listing')
            ->quotes($context, new Request(), $criteria)
            ->getQuotes();

        $quotes = [];
        foreach ($result as $quote) {
            $quotes[] = $this->snapshotMapper->toSnapshot($quote);
        }

        return new QuoteList($quotes, $result->getTotal(), $limit, $page);
    }

    #[Override]
    public function counterQuote(
        SalesChannelContext $context,
        string $quoteId,
        array $lineItems,
        ?string $comment,
    ): QuoteSnapshot {
        $this->assertCustomerHasQuoteFeature($context);

        if ([] !== $lineItems) {
            $quote = $this->loadQuote($quoteId, $context);
            $this->linePricing->applyCounterPrices($quoteId, $quote, $lineItems, $context);
        }

        $this->route($this->quoteRequestChangeRoute, 'quote request-change')->requestChange(
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
        $this->assertCustomerHasQuoteFeature($context);

        $order = $this
            ->route($this->quoteOrderRoute, 'quote order')
            ->order($context, new RequestDataBag(), $quoteId)
            ->getOrder();
        $snapshot = $this->loadSnapshot($quoteId, $context);

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
        $this->assertCustomerHasQuoteFeature($context);

        $this->route($this->quoteDeclineRoute, 'quote decline')->decline($context, $quoteId, new RequestDataBag([
            'comment' => trim($comment ?? ''),
        ]));

        return $this->loadSnapshot($quoteId, $context);
    }

    /**
     * Every mutating operation calls this first: a customer missing the flag
     * must be told which one, not handed SwagCommercial's bare 403.
     */
    private function assertCustomerHasQuoteFeature(SalesChannelContext $context): void
    {
        $customerId = $context->getCustomer()?->getId();

        if (
            null === $customerId
            || true !== $this->customerSpecificFeatureService?->isAllowed($customerId, self::CUSTOMER_FEATURE)
        ) {
            throw new ValidationException('Quote management is not enabled for this customer: customer_specific_features must contain {"QUOTE_MANAGEMENT": true}.', [
                'customer_specific_features must contain {"QUOTE_MANAGEMENT": true}',
            ]);
        }
    }

    private function loadSnapshot(string $quoteId, SalesChannelContext $context): QuoteSnapshot
    {
        return $this->snapshotMapper->toSnapshot($this->loadQuote($quoteId, $context));
    }

    /**
     * The routes above are individually nullable so the plugin degrades
     * service by service (see the constructor's docblock) — this is where a
     * missing one actually surfaces, as a legible exception instead of a
     * fatal error on a null method call.
     */
    private function route(?object $route, string $name): object
    {
        if (null === $route) {
            throw new UnsupportedCapabilityException(\sprintf('The commercial %s route is unavailable.', $name));
        }

        return $route;
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

        $quoteLoadRoute = $this->route($this->quoteLoadRoute, 'quote load');

        try {
            $quote = $quoteLoadRoute->load($quoteId, $context, $criteria)->getQuote();
        } catch (\Exception $exception) {
            throw new ResourceNotFoundException(
                \sprintf('Quote "%s" was not found for this customer.', $quoteId),
                previous: $exception,
            );
        }

        return $quote;
    }
}
