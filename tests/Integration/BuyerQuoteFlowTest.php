<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteSnapshot;
use Ucp\Sdk\Exception\ValidationException;

/**
 * The buyer path end to end through SwagCommercial's Store API routes, in a
 * real customer's sales-channel context: request a quote, read it back, list
 * it. Runs in a rolled-back transaction like every integration test here, so
 * the quotes it creates do not accumulate.
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
        $context = BuyerQuoteContextFixture::buyerContext(static::getContainer());
        $productId = BuyerQuoteFixture::anyPurchasableProductId(static::getContainer());

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
        $context = BuyerQuoteContextFixture::buyerContext(static::getContainer());
        $productId = BuyerQuoteFixture::anyPurchasableProductId(static::getContainer());
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
        $list = $this->buyerGateway()->listQuotes(
            BuyerQuoteContextFixture::buyerContext(static::getContainer()),
            5000,
            1,
        );

        self::assertSame(50, $list->limit);
    }

    public function testAnUnknownQuoteIdIsNotFound(): void
    {
        $this->expectException(\Ucp\Sdk\Exception\ResourceNotFoundException::class);

        $this->buyerGateway()->getQuote(
            BuyerQuoteContextFixture::buyerContext(static::getContainer()),
            \Shopware\Core\Framework\Uuid\Uuid::randomHex(),
        );
    }

    /**
     * The trust boundary: a real quote that belongs to somebody else must be
     * indistinguishable from one that does not exist, so a probing agent
     * cannot confirm existence.
     */
    public function testAnotherCustomersQuoteIsNotFound(): void
    {
        $context = BuyerQuoteContextFixture::buyerContext(static::getContainer());
        $foreignQuoteId = BuyerQuoteFixture::anyQuoteIdNotOwnedBy(
            static::getContainer(),
            $context->getCustomer()?->getId() ?? '',
        );

        $this->expectException(\Ucp\Sdk\Exception\ResourceNotFoundException::class);

        $this->buyerGateway()->getQuote($context, $foreignQuoteId);
    }

    /**
     * accept/decline/counter must translate an unknown or foreign id to not
     * found *before* touching the commercial mutating route, the same way
     * getQuote() already does — otherwise the customer-ownership check that
     * route performs surfaces as a raw commercial exception instead of a
     * uniform 404. One test covers all three call sites (including counter's
     * comment-only path, which skips the pricing branch that used to be the
     * only place counter() loaded the quote) rather than one test each, to
     * stay under mago's method-count ceiling.
     */
    public function testAcceptDeclineAndACommentOnlyCounterAreNotFoundForAnUnknownOrForeignId(): void
    {
        $context = BuyerQuoteContextFixture::buyerContext(static::getContainer());
        $unknownId = \Shopware\Core\Framework\Uuid\Uuid::randomHex();
        $foreignId = BuyerQuoteFixture::anyQuoteIdNotOwnedBy(
            static::getContainer(),
            $context->getCustomer()?->getId() ?? '',
        );

        foreach ([
            'acceptQuote(unknown)' => fn() => $this->buyerGateway()->acceptQuote($context, $unknownId),
            'declineQuote(unknown)' => fn() => $this->buyerGateway()->declineQuote($context, $unknownId, null),
            'counterQuote(foreign, comment only)' => fn() => $this->buyerGateway()->counterQuote(
                $context,
                $foreignId,
                [],
                'Comment only, no prices.',
            ),
        ] as $label => $operation) {
            try {
                $operation();
                self::fail($label . ' should have thrown ResourceNotFoundException.');
            } catch (\Ucp\Sdk\Exception\ResourceNotFoundException) {
                self::assertTrue(true, $label . ' correctly not found.');
            }
        }
    }

    public function testACustomerWithoutTheQuoteFeatureIsToldWhichFlagIsMissing(): void
    {
        $context = BuyerQuoteContextFixture::buyerContextWithoutQuoteFeature(static::getContainer());

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
        $context = BuyerQuoteContextFixture::buyerContext(static::getContainer());
        $productId = BuyerQuoteFixture::anyPurchasableProductId(static::getContainer());
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
     * Accepting turns a quote into an order — the one operation that attaches
     * `orderId`/`orderNumber` to the snapshot via `QuoteSnapshot::withOrder()`,
     * so this is what would catch that going wrong.
     *
     * `QuoteOrderRoute` refuses an expired quote, and a quote moved to
     * `replied` by the bare state-machine transition (rather than through the
     * admin "send quote" action this plugin does not model) has no
     * expiration date, which `QuoteEntity::isExpired()` treats as already
     * expired — TransitionTest's `updateQuote(expiresAt:)` pins the same fact.
     */
    public function testAcceptingAQuotePlacesAnOrder(): void
    {
        $context = BuyerQuoteContextFixture::buyerContext(static::getContainer());
        $productId = BuyerQuoteFixture::anyPurchasableProductId(static::getContainer());
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
}
