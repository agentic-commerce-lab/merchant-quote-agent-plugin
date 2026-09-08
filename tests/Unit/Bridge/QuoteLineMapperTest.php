<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
use MerchantQuoteAgentPlugin\Bridge\QuoteLineMapper;
use MerchantQuoteAgentPlugin\Tests\Unit\Bridge\Fixtures\LegacyLineItemEntity;
use MerchantQuoteAgentPlugin\Tests\Unit\Bridge\Fixtures\ModernLineItemEntity;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\Struct\ArrayEntity;

/**
 * Two trunk-only columns meet here: `deletedAt`, which decides whether a line
 * is skipped, and `requestedPrice`, read through QuoteLineNet. Neither exists on
 * a released SwagCommercial, and `Entity::get()` throws on both.
 *
 * The quote container itself may stay an `ArrayEntity` — `taxStatus` and
 * `lineItems` are not gated fields — but the line items are real `Entity`
 * subclasses (`Fixtures/LegacyLineItemEntity` and `.../ModernLineItemEntity`):
 * an `ArrayEntity` line item never throws on a missing key, so it could not
 * tell a passing guard from a deleted one.
 */
final class QuoteLineMapperTest extends TestCase
{
    public function testAModernShopSkipsSoftDeletedLines(): void
    {
        $lines = (new QuoteLineMapper(CommercialCapabilities::modern()))->map(self::quote([
            new ModernLineItemEntity(id: 'live', deletedAt: null, requestedPrice: null),
            new ModernLineItemEntity(id: 'gone', deletedAt: new \DateTimeImmutable(), requestedPrice: null),
        ]));

        self::assertCount(1, $lines);
        self::assertSame('live', $lines[0]->identity->lineItemId);
    }

    public function testALegacyShopKeepsEveryLineAndNeverReadsDeletedAt(): void
    {
        // LegacyLineItemEntity declares neither `deletedAt` nor
        // `requestedPrice`: on a released shop those columns do not exist and
        // reading either throws.
        $lines = (new QuoteLineMapper(CommercialCapabilities::legacy()))->map(self::quote([
            new LegacyLineItemEntity(id: 'one'),
            new LegacyLineItemEntity(id: 'two'),
        ]));

        self::assertCount(2, $lines);
        self::assertNull($lines[0]->requestedUnitPrice);
    }

    /** @param list<Entity> $lines */
    private static function quote(array $lines): Entity
    {
        return new ArrayEntity(['taxStatus' => CartPrice::TAX_STATE_NET, 'lineItems' => $lines]);
    }
}
