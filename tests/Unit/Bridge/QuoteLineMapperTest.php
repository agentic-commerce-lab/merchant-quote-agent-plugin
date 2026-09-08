<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
use MerchantQuoteAgentPlugin\Bridge\QuoteLineMapper;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\Struct\ArrayEntity;

/**
 * Two trunk-only columns meet here: `deletedAt`, which decides whether a line
 * is skipped, and `requestedPrice`, read through QuoteLineNet. Neither exists on
 * a released SwagCommercial, and `Entity::get()` throws on both.
 */
final class QuoteLineMapperTest extends TestCase
{
    public function testAModernShopSkipsSoftDeletedLines(): void
    {
        $lines = (new QuoteLineMapper(CommercialCapabilities::modern()))->map(self::quote([
            self::line('live', ['deletedAt' => null, 'requestedPrice' => null]),
            self::line('gone', ['deletedAt' => new \DateTimeImmutable(), 'requestedPrice' => null]),
        ]));

        self::assertCount(1, $lines);
        self::assertSame('live', $lines[0]->identity->lineItemId);
    }

    public function testALegacyShopKeepsEveryLineAndNeverReadsDeletedAt(): void
    {
        // Neither `deletedAt` nor `requestedPrice` is present: on a released
        // shop those columns do not exist and reading either throws.
        $lines = (new QuoteLineMapper(CommercialCapabilities::legacy()))->map(self::quote([
            self::line('one', []),
            self::line('two', []),
        ]));

        self::assertCount(2, $lines);
        self::assertNull($lines[0]->requestedUnitPrice);
    }

    /** @param list<Entity> $lines */
    private static function quote(array $lines): Entity
    {
        return new ArrayEntity(['taxStatus' => CartPrice::TAX_STATE_NET, 'lineItems' => $lines]);
    }

    /** @param array<string, mixed> $extra */
    private static function line(string $id, array $extra): Entity
    {
        return new ArrayEntity([
            'id' => $id,
            'label' => 'Widget',
            'referencedId' => 'product-1',
            'quantity' => 2,
            'totalPrice' => 20.0,
            'price' => null,
            ...$extra,
        ]);
    }
}
