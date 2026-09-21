<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Strategy;

use MerchantQuoteAgentPlugin\Strategy\Strategy;
use MerchantQuoteAgentPlugin\Strategy\StrategyResolver;
use MerchantQuoteAgentPlugin\Strategy\StrategyVersion;
use MerchantQuoteAgentPlugin\Strategy\UnknownStrategy;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

final class StrategyResolverTest extends TestCase
{
    private const STRATEGY_ID = '0123456789abcdef0123456789abcdef';

    public function testItReturnsTheNewestVersionsPromptAndId(): void
    {
        $version = new StrategyVersion();
        $version->setUniqueIdentifier('aaaabbbbccccddddeeeeffff00001111');
        $version->id = 'aaaabbbbccccddddeeeeffff00001111';
        $version->strategyId = self::STRATEGY_ID;
        $version->version = 3;
        $version->prompt = 'concede slowly';

        $resolver = new StrategyResolver($this->repository([$this->liveStrategy()]), $this->repository([$version]));

        $resolved = $resolver->resolve(self::STRATEGY_ID, Context::createDefaultContext());

        self::assertSame('aaaabbbbccccddddeeeeffff00001111', $resolved->versionId);
        self::assertSame('concede slowly', $resolved->prompt);
    }

    // The mock repository above ignores the Criteria and just hands back
    // whatever rows it was built with, so it can never prove which way the
    // resolver asked to sort -- ->first() on an already-fixed list would pass
    // whichever direction the code used. This test instead captures the
    // Criteria the resolver builds and asserts on THAT: limit 1, sorted by
    // `version` descending. That is the only way to pin the sort direction
    // without an EntityRepository that actually applies a Criteria.
    public function testItAsksForTheHighestVersionOnly(): void
    {
        $captured = null;

        $versions = $this->createMock(EntityRepository::class);
        $versions
            ->method('search')
            ->willReturnCallback(function (Criteria $criteria, Context $context) use (&$captured): EntitySearchResult {
                $captured = $criteria;

                return new EntitySearchResult('test', 0, new EntityCollection([]), null, $criteria, $context);
            });

        try {
            (new StrategyResolver($this->repository([$this->liveStrategy()]), $versions))->resolve(
                self::STRATEGY_ID,
                Context::createDefaultContext(),
            );
        } catch (UnknownStrategy) {
            // Expected: an empty result means "no version". This test is about the
            // query the resolver builds, not about what comes back from it.
        }

        self::assertInstanceOf(Criteria::class, $captured);
        self::assertSame(1, $captured->getLimit());

        $sorting = $captured->getSorting();
        self::assertCount(1, $sorting);
        self::assertSame('version', $sorting[0]->getField());
        self::assertSame(FieldSorting::DESCENDING, $sorting[0]->getDirection());
    }

    public function testItAsksOnlyForActiveVersions(): void
    {
        $captured = null;

        $versions = $this->createMock(EntityRepository::class);
        $versions
            ->method('search')
            ->willReturnCallback(function (Criteria $criteria, Context $context) use (&$captured): EntitySearchResult {
                $captured = $criteria;

                return new EntitySearchResult(
                    'merchant_quote_agent_strategy_version',
                    0,
                    new EntityCollection([]),
                    null,
                    $criteria,
                    $context,
                );
            });

        $resolver = new StrategyResolver($this->repository([$this->liveStrategy()]), $versions);

        try {
            $resolver->resolve(self::STRATEGY_ID, Context::createDefaultContext());
        } catch (UnknownStrategy) {
            // No rows come back from the stub; the Criteria is what this test is about.
        }

        self::assertInstanceOf(Criteria::class, $captured);

        $values = [];

        foreach ($captured->getFilters() as $filter) {
            if ($filter instanceof EqualsFilter) {
                $values[$filter->getField()] = $filter->getValue();
            }
        }

        self::assertSame('active', $values['status'] ?? null);
    }

    public function testAMissingStrategyIsRefused(): void
    {
        $resolver = new StrategyResolver($this->repository([]), $this->repository([]));

        $this->expectException(UnknownStrategy::class);
        $this->expectExceptionMessageMatches('/no longer exists/');

        $resolver->resolve(self::STRATEGY_ID, Context::createDefaultContext());
    }

    public function testAnArchivedStrategyIsRefused(): void
    {
        $archived = $this->liveStrategy();
        $archived->archivedAt = new \DateTimeImmutable('2026-09-01 10:00:00');

        $resolver = new StrategyResolver($this->repository([$archived]), $this->repository([]));

        $this->expectException(UnknownStrategy::class);
        $this->expectExceptionMessageMatches('/archived/');

        $resolver->resolve(self::STRATEGY_ID, Context::createDefaultContext());
    }

    public function testAStrategyWithNoVersionIsRefused(): void
    {
        $resolver = new StrategyResolver($this->repository([$this->liveStrategy()]), $this->repository([]));

        $this->expectException(UnknownStrategy::class);

        $resolver->resolve(self::STRATEGY_ID, Context::createDefaultContext());
    }

    private function liveStrategy(): Strategy
    {
        $strategy = new Strategy();
        $strategy->setUniqueIdentifier(self::STRATEGY_ID);
        $strategy->id = self::STRATEGY_ID;
        $strategy->name = 'House style';

        return $strategy;
    }

    /** @param list<object> $entities */
    private function repository(array $entities): EntityRepository
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->method('search')
            ->willReturnCallback(function (Criteria $criteria, Context $context) use ($entities): EntitySearchResult {
                return new EntitySearchResult(
                    'test',
                    \count($entities),
                    new EntityCollection($entities),
                    null,
                    $criteria,
                    $context,
                );
            });

        return $repository;
    }
}
