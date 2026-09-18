<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\BuyerQuoteGatewayInterface;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Ucp\Sdk\Exception\ValidationException;

/**
 * The shopping assistant's request shape: the cart is already full, and the
 * request adds nothing.
 *
 * BuyerQuoteFlowTest covers the UCP shape, where the cart starts empty and
 * every line is an addition. This covers the other one, and the guard that now
 * separates them.
 */
final class AssistantCartQuoteTest extends IntegrationTestCase
{
    public function testAnEmptyLineItemListQuotesTheCartThatIsAlreadyThere(): void
    {
        $context = BuyerQuoteContextFixture::buyerContext(static::getContainer());
        $cartService = static::getContainer()->get(CartService::class);
        self::assertInstanceOf(CartService::class, $cartService);

        $productId = BuyerQuoteFixture::anyPurchasableProductId(static::getContainer());
        $cart = $cartService->getCart($context->getToken(), $context);
        $cartService->add(
            $cart,
            [BuyerQuoteFixture::lineItem(static::getContainer(), $productId, 4, $context)],
            $context,
        );

        $gateway = static::getContainer()->get(BuyerQuoteGatewayInterface::class);
        self::assertInstanceOf(BuyerQuoteGatewayInterface::class, $gateway);

        $snapshot = $gateway->requestQuote($context, [], 'Can you do better on these?');

        self::assertCount(1, $snapshot->lineItems);
        self::assertSame(4, $snapshot->lineItems[0]['quantity']);
    }

    public function testAnEmptyCartWithAnEmptyRequestStillFails(): void
    {
        $context = BuyerQuoteContextFixture::buyerContext(static::getContainer());

        $gateway = static::getContainer()->get(BuyerQuoteGatewayInterface::class);
        self::assertInstanceOf(BuyerQuoteGatewayInterface::class, $gateway);

        $this->expectException(ValidationException::class);

        $gateway->requestQuote($context, [], null);
    }

    public function testAPriceOnlyLineAsksWithoutAddingASecondLine(): void
    {
        $context = BuyerQuoteContextFixture::buyerContext(static::getContainer());
        $cartService = static::getContainer()->get(CartService::class);
        self::assertInstanceOf(CartService::class, $cartService);

        $productId = BuyerQuoteFixture::anyPurchasableProductId(static::getContainer());
        $cart = $cartService->getCart($context->getToken(), $context);
        $cartService->add(
            $cart,
            [BuyerQuoteFixture::lineItem(static::getContainer(), $productId, 2, $context)],
            $context,
        );

        $gateway = static::getContainer()->get(BuyerQuoteGatewayInterface::class);
        self::assertInstanceOf(BuyerQuoteGatewayInterface::class, $gateway);

        $snapshot = $gateway->requestQuote(
            $context,
            [['product_id' => $productId, 'requested_unit_price' => '98.00']],
            null,
        );

        self::assertCount(1, $snapshot->lineItems);
        self::assertSame(2, $snapshot->lineItems[0]['quantity']);
        self::assertSame(98.00, $snapshot->lineItems[0]['requested_unit_price']);
    }
}
