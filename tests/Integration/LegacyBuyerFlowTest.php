<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
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
