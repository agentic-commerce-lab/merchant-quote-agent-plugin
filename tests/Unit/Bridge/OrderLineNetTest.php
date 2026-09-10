<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge;

use MerchantQuoteAgentPlugin\Bridge\OrderLineNet;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRule;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Framework\Struct\ArrayEntity;

/**
 * Uses the measured live-shop case from order 10001: a 15-unit line at 410.00
 * gross with 981.93 tax for the WHOLE line. `(6150.00 - 981.93) / 15 = 344.54`,
 * which also reconciles with the order's amount_net (5168.07) to the cent.
 */
final class OrderLineNetTest extends TestCase
{
    public function testAGrossOrderLineHasItsTaxRemoved(): void
    {
        $unit = OrderLineNet::of(self::line(quantity: 15, totalPrice: 6150.0, tax: 981.93), CartPrice::TAX_STATE_GROSS);

        self::assertSame(344.54, $unit);
    }

    public function testANetOrderLineIsAlreadyNet(): void
    {
        // Same stored numbers, but the order says net: nothing comes off.
        $unit = OrderLineNet::of(self::line(quantity: 15, totalPrice: 6150.0, tax: 981.93), CartPrice::TAX_STATE_NET);

        self::assertSame(410.0, $unit);
    }

    public function testATaxFreeOrderLineIsAlreadyNet(): void
    {
        $unit = OrderLineNet::of(self::line(quantity: 15, totalPrice: 6150.0, tax: 981.93), CartPrice::TAX_STATE_FREE);

        self::assertSame(410.0, $unit);
    }

    private static function line(int $quantity, float $totalPrice, float $tax): ArrayEntity
    {
        return new ArrayEntity([
            'quantity' => $quantity,
            'totalPrice' => $totalPrice,
            'price' => new CalculatedPrice(
                $totalPrice,
                $totalPrice,
                new CalculatedTaxCollection([new CalculatedTax($tax, 19.0, $totalPrice)]),
                new TaxRuleCollection([new TaxRule(19.0)]),
            ),
        ]);
    }
}
