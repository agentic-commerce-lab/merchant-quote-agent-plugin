<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge;

use MerchantQuoteAgentPlugin\Bridge\History\OrderHistoryAggregation;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\AggregationResultCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\Bucket;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\TermsResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\CountResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\MaxResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\SumResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;

final class OrderHistoryAggregationTest extends TestCase
{
    public function testSingleCurrencyCoversEveryOrder(): void
    {
        $stats = OrderHistoryAggregation::stateOf(self::searchResult([new Bucket('EUR', 2, null)]));
        self::assertSame(2, $stats->count);
        self::assertSame(300.5, $stats->lifetimeNet);
        self::assertSame('EUR', $stats->currencyIso);
        self::assertNull($stats->unavailableReason);
        self::assertSame('2026-09-08', $stats->lastOrderAt?->format('Y-m-d'));
    }

    #[TestWith([[['EUR', 1], ['USD', 1]]], 'mixed currencies')]
    #[TestWith([[[null, 2]]], 'missing ISO')]
    #[TestWith([[['', 2]]], 'empty ISO')]
    #[TestWith([[[' ', 2]]], 'blank ISO')]
    #[TestWith([[['EUR', 1]]], 'one order missing currency')]
    #[TestWith([[['EUR', 3]]], 'bucket exceeds count')]
    #[TestWith([[]], 'no buckets')]
    public function testIncompleteOrMixedCurrenciesSuppressOnlyMoney(array $buckets): void
    {
        $stats = OrderHistoryAggregation::stateOf(self::searchResult(array_map(
            static fn(array $b): Bucket => new Bucket($b[0], $b[1], null),
            $buckets,
        )));
        self::assertNull($stats->lifetimeNet);
        self::assertNull($stats->currencyIso);
        self::assertNotEmpty($stats->unavailableReason);
        self::assertSame(2, $stats->count);
        self::assertSame('2026-09-08', $stats->lastOrderAt?->format('Y-m-d'));
    }

    public function testMissingCurrencyAggregationCannotClaimKnownMoney(): void
    {
        $stats = OrderHistoryAggregation::stateOf(self::searchResult(null));
        self::assertNull($stats->lifetimeNet);
        self::assertNotEmpty($stats->unavailableReason);
    }

    public function testKnownEmptyOrdersHaveZeroTotalWithoutInventingCurrency(): void
    {
        $stats = OrderHistoryAggregation::stateOf(self::searchResult([], 0));
        self::assertSame(0, $stats->count);
        self::assertSame(0.0, $stats->lifetimeNet);
        self::assertNull($stats->currencyIso);
        self::assertNull($stats->lastOrderAt);
        self::assertNull($stats->unavailableReason);
    }

    public function testMissingAggregationsDoNotMeanKnownZeroSpend(): void
    {
        $result = new EntitySearchResult(
            'order',
            0,
            new EntityCollection(),
            null,
            new Criteria(),
            Context::createDefaultContext(),
        );
        self::assertNull(OrderHistoryAggregation::stateOf($result)->lifetimeNet);
    }

    /** @param list<Bucket>|null $buckets
     * @return EntitySearchResult<EntityCollection>
     */
    private static function searchResult(?array $buckets, int $count = 2): EntitySearchResult
    {
        $aggregations = new AggregationResultCollection([
            new CountResult('orderCount', $count),
            new SumResult('lifetimeNet', $count === 0 ? 0.0 : 300.5),
            new MaxResult('lastOrderAt', $count === 0 ? null : '2026-09-08 10:00:00'),
        ]);
        if ($buckets !== null) {
            $aggregations->add(new TermsResult('currencies', $buckets));
        }
        return new EntitySearchResult(
            'order',
            0,
            new EntityCollection(),
            $aggregations,
            new Criteria(),
            Context::createDefaultContext(),
        );
    }
}
