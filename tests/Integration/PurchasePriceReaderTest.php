<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Negotiation\PurchasePricesInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\System\Currency\CurrencyEntity;

/** Real DAL inheritance and currency fallback; every write is rolled back. */
final class PurchasePriceReaderTest extends IntegrationTestCase
{
    public function testAVariantInheritsItsParentsPurchasePrice(): void
    {
        $variantId = self::variantPricedOnlyOnItsParent(40.0);

        $prices = self::reader()->netUnitPrices([$variantId], self::defaultCurrency()->getIsoCode());

        self::assertSame([$variantId => 40.0], $prices);
    }

    public function testADefaultCurrencyOnlyPriceIsConvertedByTheQuoteCurrencysFactor(): void
    {
        $variantId = self::variantPricedOnlyOnItsParent(40.0);
        $usd = self::currency('USD');

        $prices = self::reader()->netUnitPrices([$variantId], 'USD');

        self::assertEqualsWithDelta(40.0 * $usd->getFactor(), $prices[$variantId] ?? null, 0.0001);
    }

    public function testNonProductIdsAndUnknownCurrenciesYieldNothing(): void
    {
        $variantId = self::variantPricedOnlyOnItsParent(40.0);

        self::assertSame([], self::reader()->netUnitPrices(['not-a-uuid', ''], 'EUR'));
        self::assertSame([], self::reader()->netUnitPrices([$variantId], 'XXX'));
    }

    private static function reader(): PurchasePricesInterface
    {
        $reader = static::getContainer()->get(PurchasePricesInterface::class);
        self::assertInstanceOf(PurchasePricesInterface::class, $reader);

        return $reader;
    }

    /** A variant with no purchase price of its own, whose parent's is `$net` in the default currency only. */
    private static function variantPricedOnlyOnItsParent(float $net): string
    {
        $criteria = (new Criteria())
            ->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [new EqualsFilter('parentId', null)]))
            ->setLimit(1);
        $variant = self::repository(static::getContainer(), 'product.repository')
            ->search($criteria, Context::createDefaultContext())
            ->first();
        self::assertNotNull($variant, 'The test shop has no variant product to read.');
        $parentId = $variant->get('parentId');
        self::assertIsString($parentId);
        $variantId = (string) $variant->get('id');

        self::repository(static::getContainer(), 'product.repository')
            ->update([
                [
                    'id' => $parentId,
                    'purchasePrices' => [[
                        'currencyId' => Defaults::CURRENCY,
                        'net' => $net,
                        'gross' => $net,
                        'linked' => false,
                    ]],
                ],
                ['id' => $variantId, 'purchasePrices' => null],
            ], Context::createDefaultContext());

        return $variantId;
    }

    private static function defaultCurrency(): CurrencyEntity
    {
        $currency = self::repository(static::getContainer(), 'currency.repository')
            ->search(new Criteria([Defaults::CURRENCY]), Context::createDefaultContext())
            ->first();
        self::assertInstanceOf(CurrencyEntity::class, $currency);

        return $currency;
    }

    private static function currency(string $iso): CurrencyEntity
    {
        $currency = self::repository(static::getContainer(), 'currency.repository')
            ->search((new Criteria())->addFilter(new EqualsFilter('isoCode', $iso)), Context::createDefaultContext())
            ->first();
        self::assertInstanceOf(CurrencyEntity::class, $currency, "The test shop has no {$iso} currency.");
        self::assertNotSame(Defaults::CURRENCY, $currency->getId(), "{$iso} must not be the default currency.");

        return $currency;
    }
}
