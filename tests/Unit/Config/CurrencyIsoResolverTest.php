<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Config;

use MerchantQuoteAgentPlugin\Config\CurrencyIsoResolver;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\Currency\CurrencyDefinition;
use Shopware\Core\System\Currency\CurrencyEntity;

/**
 * The admin's price field to the ISO-keyed map QuoteLimits reads.
 *
 * The shapes here are what Shopware actually persists for `type="price"`, so
 * a change in that shape fails these rather than silently reading no ceiling
 * at all — which would auto-answer every quote it was meant to escalate.
 */
final class CurrencyIsoResolverTest extends TestCase
{
    private const EUR = '0191c1b0000071ac9ba7f1eb00000001';

    private const USD = '0191c1b0000071ac9ba7f1eb00000002';

    public function testEachPriceRowBecomesItsCurrencysIsoCode(): void
    {
        // Values are re-keyed, not coerced: the int stays an int here and
        // QuoteLimits does the numeric narrowing, which is what makes a
        // wrong-typed one a named error rather than a silently dropped row.
        $resolved = $this->resolver(['EUR' => self::EUR, 'USD' => self::USD])->netByIso([
            ['currencyId' => self::EUR, 'net' => 50_000.0, 'gross' => 50_000.0, 'linked' => true],
            ['currencyId' => self::USD, 'net' => 55_000, 'gross' => 55_000, 'linked' => true],
        ]);

        self::assertSame(['EUR' => 50_000.0, 'USD' => 55_000], $resolved);
    }

    public function testAWrongTypedNetIsCarriedThroughForTheFactoryToRefuse(): void
    {
        // `system:config:set` without `--json` stores a string. Dropping the
        // row would read as "no ceiling for EUR" and escalate everything in
        // silence; carrying it through makes the factory name the field.
        $resolved = $this->resolver(['EUR' => self::EUR])->netByIso([
            ['currencyId' => self::EUR, 'net' => '50000'],
        ]);

        self::assertSame(['EUR' => '50000'], $resolved);
    }

    public function testABlankNetIsNotAZeroCeiling(): void
    {
        // Zero means "escalate everything"; blank means the merchant never set
        // this currency. Reading blank as zero would take a whole currency out
        // of service, and reading zero as blank would auto-answer everything.
        $resolved = $this->resolver(['EUR' => self::EUR, 'USD' => self::USD])->netByIso([
            ['currencyId' => self::EUR, 'net' => null, 'gross' => null, 'linked' => true],
            ['currencyId' => self::USD, 'net' => 0.0, 'gross' => 0.0, 'linked' => true],
        ]);

        self::assertSame(['USD' => 0.0], $resolved);
    }

    public function testACurrencyThatNoLongerExistsDropsOutRatherThanKeyingTheMapByAUuid(): void
    {
        // A uuid key would never match a quote's ISO code, so the ceiling would
        // read as configured-but-unmatched and escalate every quote. Dropping
        // it makes the currency simply unconfigured, which is the same thing
        // the merchant would see if they had never filled it in.
        $resolved = $this->resolver(['EUR' => self::EUR])->netByIso([
            ['currencyId' => self::EUR, 'net' => 50_000.0],
            ['currencyId' => self::USD, 'net' => 55_000.0],
        ]);

        self::assertSame(['EUR' => 50_000.0], $resolved);
    }

    public function testAValueThatIsNotAPriceListIsLeftForTheFactoryToRefuse(): void
    {
        // Null tells the reader to leave the raw value alone, so an unset
        // field stays unset and a wrong-typed scalar still reaches the factory
        // under its own name.
        $resolver = $this->resolver([]);

        self::assertNull($resolver->netByIso(null));
        self::assertNull($resolver->netByIso('50000'));
        self::assertSame([], $resolver->netByIso([]));
    }

    /** @param array<string, string> $isoToId */
    private function resolver(array $isoToId): CurrencyIsoResolver
    {
        $currencies = [];

        foreach ($isoToId as $iso => $id) {
            $currency = new CurrencyEntity();
            $currency->setUniqueIdentifier($id);
            $currency->setId($id);
            $currency->setIsoCode($iso);
            $currencies[] = $currency;
        }

        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->method('search')
            ->willReturn(
                new EntitySearchResult(
                    CurrencyDefinition::ENTITY_NAME,
                    \count($currencies),
                    new EntityCollection($currencies),
                    null,
                    new Criteria(),
                    Context::createDefaultContext(),
                ),
            );

        return new CurrencyIsoResolver($repository);
    }
}
