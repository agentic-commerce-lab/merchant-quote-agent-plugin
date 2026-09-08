<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
use MerchantQuoteAgentPlugin\Bridge\QuoteLineNet;
use MerchantQuoteAgentPlugin\Tests\Unit\Bridge\Fixtures\LegacyLineItemEntity;
use MerchantQuoteAgentPlugin\Tests\Unit\Bridge\Fixtures\ModernLineItemEntity;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;

/**
 * On released SwagCommercial there is no `requested_price` column, and
 * `Entity::get()` throws `propertyNotFound` rather than returning null — so the
 * capability flag is what keeps a legacy shop from taking down every servicing
 * pass on its first quote.
 *
 * The line items here are real `Entity` subclasses (see `Fixtures/`), not
 * `ArrayEntity`: `ArrayEntity::get()` is a plain array lookup that never
 * throws, so a legacy-shaped `ArrayEntity` would pass these tests identically
 * with the guard deleted. `LegacyLineItemEntity` declares no `requestedPrice`
 * property at all, so reading it throws exactly as a released shop's DAL
 * entity would.
 */
final class QuoteLineNetTest extends TestCase
{
    public function testAModernShopReadsTheBuyersAsk(): void
    {
        $net = QuoteLineNet::of(
            new ModernLineItemEntity(totalPrice: 100.0, quantity: 4, requestedPrice: 8.0),
            CartPrice::TAX_STATE_NET,
            CommercialCapabilities::modern(),
        );

        self::assertSame(8.0, $net->requestedUnitPrice);
    }

    public function testALegacyShopReportsNoAskAndDoesNotTouchTheField(): void
    {
        // LegacyLineItemEntity declares no `requestedPrice` property at all:
        // reading it would throw, which is exactly the production failure
        // this guards.
        $net = QuoteLineNet::of(
            new LegacyLineItemEntity(totalPrice: 100.0, quantity: 4),
            CartPrice::TAX_STATE_NET,
            CommercialCapabilities::legacy(),
        );

        self::assertNull($net->requestedUnitPrice);
    }

    public function testALegacyShopStillDerivesUnitAndTotalNet(): void
    {
        $net = QuoteLineNet::of(
            new LegacyLineItemEntity(totalPrice: 100.0, quantity: 4),
            CartPrice::TAX_STATE_NET,
            CommercialCapabilities::legacy(),
        );

        self::assertSame(100.0, $net->total);
        self::assertSame(25.0, $net->unitPrice);
    }
}
