<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
use MerchantQuoteAgentPlugin\Bridge\QuoteLineNet;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\Struct\ArrayEntity;

/**
 * On released SwagCommercial there is no `requested_price` column, and
 * `Entity::get()` throws `propertyNotFound` rather than returning null — so the
 * capability flag is what keeps a legacy shop from taking down every servicing
 * pass on its first quote.
 */
final class QuoteLineNetTest extends TestCase
{
    public function testAModernShopReadsTheBuyersAsk(): void
    {
        $net = QuoteLineNet::of(
            self::lineItem(['requestedPrice' => 8.0]),
            CartPrice::TAX_STATE_NET,
            CommercialCapabilities::modern(),
        );

        self::assertSame(8.0, $net->requestedUnitPrice);
    }

    public function testALegacyShopReportsNoAskAndDoesNotTouchTheField(): void
    {
        // No `requestedPrice` key at all: reading it would throw, which is
        // exactly the production failure this guards.
        $net = QuoteLineNet::of(self::lineItem([]), CartPrice::TAX_STATE_NET, CommercialCapabilities::legacy());

        self::assertNull($net->requestedUnitPrice);
    }

    public function testALegacyShopStillDerivesUnitAndTotalNet(): void
    {
        $net = QuoteLineNet::of(self::lineItem([]), CartPrice::TAX_STATE_NET, CommercialCapabilities::legacy());

        self::assertSame(100.0, $net->total);
        self::assertSame(25.0, $net->unitPrice);
    }

    /** @param array<string, mixed> $extra */
    private static function lineItem(array $extra): Entity
    {
        return new ArrayEntity(['totalPrice' => 100.0, 'quantity' => 4, 'price' => null, ...$extra]);
    }
}
