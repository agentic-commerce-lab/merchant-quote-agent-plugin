<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\BuyerQuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\SalesChannelContextResolver;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteSnapshot;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\RequestContext;

/**
 * The buyer path end to end through SwagCommercial's Store API routes, in a
 * real customer's sales-channel context: request a quote, read it back, list
 * it. Runs in a rolled-back transaction like every integration test here, so
 * the quotes it creates do not accumulate.
 *
 * @mago-expect lint:too-many-methods
 * Eight cases against six operations plus four shared context-building
 * helpers is the surface of the buyer gateway's contract, not a class with
 * behaviour to split — the money-path tests (counter/decline, accept/order)
 * are exactly the coverage the review round asked for; fewer methods here
 * would mean fewer cases, not a better boundary.
 */
final class BuyerQuoteFlowTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!CommercialAvailability::isLicensed()) {
            self::markTestSkipped('SwagCommercial quote management is not licensed in this shop.');
        }
    }

    public function testItRequestsAQuoteAndReadsItBack(): void
    {
        $context = $this->buyerContext();
        $productId = BuyerQuoteFixture::anyPurchasableProductId(static::getContainer(), $context->getContext());

        $snapshot = $this->buyerGateway()->requestQuote(
            $context,
            [['product_id' => $productId, 'quantity' => 3, 'requested_unit_price' => 9.99]],
            'Three of these, please.',
        );

        self::assertNotSame('', $snapshot->id);
        self::assertSame('open', $snapshot->state);
        self::assertCount(1, $snapshot->lineItems);
        self::assertSame(9.99, $snapshot->lineItems[0]['requested_unit_price']);

        $reread = $this->buyerGateway()->getQuote($context, $snapshot->id);

        self::assertSame($snapshot->id, $reread->id);
        self::assertNotSame([], $reread->comments);
    }

    public function testItListsOnlyTheAuthenticatedCustomersQuotes(): void
    {
        $context = $this->buyerContext();
        $productId = BuyerQuoteFixture::anyPurchasableProductId(static::getContainer(), $context->getContext());
        $foreignQuoteId = BuyerQuoteFixture::anyQuoteIdNotOwnedBy(
            static::getContainer(),
            $context->getCustomer()?->getId() ?? '',
        );

        $created = $this->buyerGateway()->requestQuote($context, [['product_id' => $productId, 'quantity' => 1]], null);
        $list = $this->buyerGateway()->listQuotes($context, 25, 1);

        $ids = array_map(static fn(QuoteSnapshot $quote): string => $quote->id, $list->quotes);

        self::assertContains($created->id, $ids);
        self::assertNotContains($foreignQuoteId, $ids);
    }

    /** "An agent must not be able to ask for the whole table." */
    public function testListQuotesClampsTheLimitToFifty(): void
    {
        $list = $this->buyerGateway()->listQuotes($this->buyerContext(), 5000, 1);

        self::assertSame(50, $list->limit);
    }

    public function testAnUnknownQuoteIdIsNotFound(): void
    {
        $this->expectException(\Ucp\Sdk\Exception\ResourceNotFoundException::class);

        $this->buyerGateway()->getQuote($this->buyerContext(), \Shopware\Core\Framework\Uuid\Uuid::randomHex());
    }

    /**
     * The trust boundary: a real quote that belongs to somebody else must be
     * indistinguishable from one that does not exist, so a probing agent
     * cannot confirm existence.
     */
    public function testAnotherCustomersQuoteIsNotFound(): void
    {
        $context = $this->buyerContext();
        $foreignQuoteId = BuyerQuoteFixture::anyQuoteIdNotOwnedBy(
            static::getContainer(),
            $context->getCustomer()?->getId() ?? '',
        );

        $this->expectException(\Ucp\Sdk\Exception\ResourceNotFoundException::class);

        $this->buyerGateway()->getQuote($context, $foreignQuoteId);
    }

    public function testACustomerWithoutTheQuoteFeatureIsToldWhichFlagIsMissing(): void
    {
        $context = $this->buyerContextWithoutQuoteFeature();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/QUOTE_MANAGEMENT/');

        $this->buyerGateway()->requestQuote(
            $context,
            [['product_id' => \Shopware\Core\Framework\Uuid\Uuid::randomHex(), 'quantity' => 1]],
            null,
        );
    }

    /**
     * A full negotiation cycle: the merchant side moves the quote through the
     * state machine (`open --sent--> replied`, `change_requested
     * --process--> in_review --sent--> replied` per this shop's `quote.state`
     * machine — TransitionTest pins the same graph), the buyer side counters
     * and then declines. Both gateways act on the same underlying quote, in
     * the one rolled-back transaction every integration test here runs in.
     */
    public function testACounterOfferCanThenBeDeclined(): void
    {
        $context = $this->buyerContext();
        $productId = BuyerQuoteFixture::anyPurchasableProductId(static::getContainer(), $context->getContext());
        $merchantGateway = static::gateway();

        $snapshot = $this->buyerGateway()->requestQuote(
            $context,
            [['product_id' => $productId, 'quantity' => 1]],
            null,
        );
        self::assertSame('open', $snapshot->state);

        $merchantGateway->transition($snapshot->id, QuoteTransition::Sent);

        $countered = $this->buyerGateway()->counterQuote(
            $context,
            $snapshot->id,
            [['product_id' => $productId, 'requested_unit_price' => 1.23]],
            'How about this instead?',
        );
        self::assertSame('change_requested', $countered->state);

        $merchantGateway->transition($snapshot->id, QuoteTransition::Process);
        $merchantGateway->transition($snapshot->id, QuoteTransition::Sent);

        $declined = $this->buyerGateway()->declineQuote($context, $snapshot->id, 'No thanks.');

        self::assertSame('declined', $declined->state);
    }

    /**
     * Accepting turns a quote into an order — the one operation that rebuilds
     * a `QuoteSnapshot` field by field (`orderId`/`orderNumber` added), so
     * this is what would catch a transposed field there.
     *
     * `QuoteOrderRoute` refuses an expired quote, and a quote moved to
     * `replied` by the bare state-machine transition (rather than through the
     * admin "send quote" action this plugin does not model) has no
     * expiration date, which `QuoteEntity::isExpired()` treats as already
     * expired — TransitionTest's `updateQuote(expiresAt:)` pins the same fact.
     */
    public function testAcceptingAQuotePlacesAnOrder(): void
    {
        $context = $this->buyerContext();
        $productId = BuyerQuoteFixture::anyPurchasableProductId(static::getContainer(), $context->getContext());
        $merchantGateway = static::gateway();

        $snapshot = $this->buyerGateway()->requestQuote(
            $context,
            [['product_id' => $productId, 'quantity' => 1]],
            null,
        );
        $merchantGateway->updateQuote($snapshot->id, new QuoteUpdate(expiresAt: new \DateTimeImmutable('+14 days')));
        $merchantGateway->transition($snapshot->id, QuoteTransition::Sent);

        $accepted = $this->buyerGateway()->acceptQuote($context, $snapshot->id);

        self::assertSame('accepted', $accepted->state);
        self::assertNotNull($accepted->orderId);
        self::assertNotNull($accepted->orderNumber);
    }

    private function buyerGateway(): BuyerQuoteGatewayInterface
    {
        $gateway = static::getContainer()->get(BuyerQuoteGatewayInterface::class);
        self::assertInstanceOf(BuyerQuoteGatewayInterface::class, $gateway);

        return $gateway;
    }

    private function buyerContext(): \Shopware\Core\System\SalesChannel\SalesChannelContext
    {
        return $this->contextFor(BuyerQuoteFixture::anyQuoteCapableCustomerId(static::getContainer()));
    }

    private function buyerContextWithoutQuoteFeature(): \Shopware\Core\System\SalesChannel\SalesChannelContext
    {
        return $this->contextFor(BuyerQuoteFixture::anyCustomerWithoutQuoteFeature(static::getContainer()));
    }

    private function contextFor(string $customerId): \Shopware\Core\System\SalesChannel\SalesChannelContext
    {
        $resolver = static::getContainer()->get(SalesChannelContextResolver::class);
        self::assertInstanceOf(SalesChannelContextResolver::class, $resolver);

        return $resolver->resolveForCustomer(
            $customerId,
            new RequestContext(BuyerQuoteFixture::storefrontHost(static::getContainer())),
        );
    }
}
