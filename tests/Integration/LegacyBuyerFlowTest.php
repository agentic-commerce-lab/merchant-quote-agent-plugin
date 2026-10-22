<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingTrigger;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\QuoteTriggerEventFixture;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Ucp\Sdk\Exception\ValidationException;

/**
 * The buyer surface against a released SwagCommercial.
 *
 * Skipped on a modern shop rather than duplicated: BuyerQuoteFlowTest already
 * covers trunk, and the cases here are specifically about what a released
 * backend does differently — one-step quote creation, and a per-line ask that
 * must be refused rather than dropped.
 */
final class LegacyBuyerFlowTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $capabilities = static::getContainer()->get(CommercialCapabilities::class);
        self::assertInstanceOf(CommercialCapabilities::class, $capabilities);

        if ($capabilities->lineItemAsks) {
            self::markTestSkipped('This shop has line-item asks; BuyerQuoteFlowTest covers it.');
        }
    }

    public function testTheGatewayIsAvailableWithoutTheSendRoute(): void
    {
        self::assertTrue(static::buyerGateway()->isAvailable());
    }

    public function testAQuoteRequestLandsOpenInOneCallAndCarriesTheComment(): void
    {
        $context = BuyerQuoteContextFixture::buyerContext(static::getContainer());
        $productId = BuyerQuoteFixture::anyPurchasableProductId(static::getContainer());

        $snapshot = static::buyerGateway()
            ->requestQuote($context, [['product_id' => $productId, 'quantity' => 3]], 'Please quote 3 units.');

        self::assertSame('open', $snapshot->state);
        self::assertNotSame([], $snapshot->comments);
        self::assertSame('Please quote 3 units.', $snapshot->comments[0]['comment']);
    }

    /**
     * A released route creates the quote straight in `open`, so no state
     * transition fires, and an empty dialog writes no comment. The request
     * event is then the only thing that tells the agent a buyer asked.
     */
    public function testARequestWithAnEmptyDialogQueuesTheQuoteForServicing(): void
    {
        $context = BuyerQuoteContextFixture::buyerContext(static::getContainer());
        $productId = BuyerQuoteFixture::anyPurchasableProductId(static::getContainer());
        $dispatcher = static::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $bus = QuoteTriggerEventFixture::collectingBus();
        $trigger = new QuoteServicingTrigger($bus);

        $dispatcher->addSubscriber($trigger);

        try {
            $snapshot = static::buyerGateway()
                ->requestQuote($context, [['product_id' => $productId, 'quantity' => 1]], null);
        } finally {
            $dispatcher->removeSubscriber($trigger);
        }

        self::assertSame(
            [[$snapshot->id, 'quote_requested']],
            array_map(static fn(ServiceQuoteMessage $m): array => [$m->quoteId, $m->reason], $bus->messages),
        );
    }

    public function testEveryPublishedLineReportsNoBuyerAsk(): void
    {
        $context = BuyerQuoteContextFixture::buyerContext(static::getContainer());
        $productId = BuyerQuoteFixture::anyPurchasableProductId(static::getContainer());

        $snapshot = static::buyerGateway()
            ->requestQuote($context, [['product_id' => $productId, 'quantity' => 1]], null);

        self::assertNull($snapshot->lineItems[0]['requested_unit_price']);
    }

    public function testAPerLineAskOnARequestIsRefusedRatherThanDropped(): void
    {
        $context = BuyerQuoteContextFixture::buyerContext(static::getContainer());
        $productId = BuyerQuoteFixture::anyPurchasableProductId(static::getContainer());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('does not support per-line price asks');

        static::buyerGateway()
            ->requestQuote(
                $context,
                [['product_id' => $productId, 'quantity' => 1, 'requested_unit_price' => 4.5]],
                null,
            );
    }

    public function testACounterWithLineItemsIsRefused(): void
    {
        $context = BuyerQuoteContextFixture::buyerContext(static::getContainer());
        $productId = BuyerQuoteFixture::anyPurchasableProductId(static::getContainer());

        $snapshot = static::buyerGateway()
            ->requestQuote($context, [['product_id' => $productId, 'quantity' => 2]], null);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('does not support per-line price asks');

        static::buyerGateway()
            ->counterQuote(
                $context,
                $snapshot->id,
                [['product_id' => $productId, 'requested_unit_price' => 4.5]],
                null,
            );
    }
}
