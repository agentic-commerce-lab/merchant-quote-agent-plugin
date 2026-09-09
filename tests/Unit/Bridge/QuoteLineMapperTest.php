<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge;

use MerchantQuoteAgentPlugin\Bridge\MirroredAsks;
use MerchantQuoteAgentPlugin\Bridge\QuoteLineMapper;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Framework\Struct\ArrayEntity;

/**
 * The mirror is write-only: the quote SHOWS a chat ask the agent wrote onto a
 * line, and the agent's own read model does not. Every guard that treats
 * `requested_price` as buyer-only reads a line through this mapper, so one
 * subtraction here is what keeps all of them seeing what they saw before —
 * see MirroredAsks for which three and why.
 *
 * The 19% gross fixture is the shop's real shape: `requested_price` is stored
 * in the quote's own tax space, so 95.20 gross is the 80.00 net ask.
 */
final class QuoteLineMapperTest extends TestCase
{
    public function testARequestedPriceTheAgentMirroredIsHiddenFromTheReadModel(): void
    {
        $lines = (new QuoteLineMapper())->map(self::quote(requestedPriceGross: 95.20, customFields: MirroredAsks::stamp([], [
            'line-1' => 80.0,
        ])));

        self::assertNull($lines[0]->requestedUnitPrice);
    }

    public function testARequestedPriceTheBUYERPlacedIsReadAsAlways(): void
    {
        $lines = (new QuoteLineMapper())->map(self::quote(requestedPriceGross: 95.20, customFields: []));

        self::assertSame(80.0, $lines[0]->requestedUnitPrice);
    }

    /** The whole point of the tolerance being a cent and not "any value on a marked line". */
    public function testABuyerEditingOverAMirroredAskIsReadAgain(): void
    {
        $lines = (new QuoteLineMapper())->map(self::quote(requestedPriceGross: 90.00, customFields: MirroredAsks::stamp([], [
            'line-1' => 80.0,
        ])));

        self::assertSame(75.63, $lines[0]->requestedUnitPrice);
    }

    public function testAMirrorOnAnotherLineDoesNotHideThisLinesAsk(): void
    {
        $lines = (new QuoteLineMapper())->map(self::quote(requestedPriceGross: 95.20, customFields: MirroredAsks::stamp([], [
            'line-2' => 80.0,
        ])));

        self::assertSame(80.0, $lines[0]->requestedUnitPrice);
    }

    /** @param array<string, mixed> $customFields */
    private static function quote(float $requestedPriceGross, array $customFields): ArrayEntity
    {
        $taxes = new CalculatedTaxCollection([new CalculatedTax(19.0, 19.0, 119.0)]);

        return new ArrayEntity([
            'taxStatus' => 'gross',
            'customFields' => $customFields,
            'lineItems' => [
                new ArrayEntity([
                    'id' => 'line-1',
                    'label' => 'Widget',
                    'referencedId' => 'product-1',
                    'quantity' => 1,
                    'totalPrice' => 119.0,
                    'requestedPrice' => $requestedPriceGross,
                    'price' => new CalculatedPrice(119.0, 119.0, $taxes, new TaxRuleCollection()),
                ]),
            ],
        ]);
    }
}
