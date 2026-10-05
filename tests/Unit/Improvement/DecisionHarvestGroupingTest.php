<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Improvement;

use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use MerchantQuoteAgentPlugin\Improvement\DecisionHarvest;
use MerchantQuoteAgentPlugin\Improvement\ImprovementWindow;
use MerchantQuoteAgentPlugin\Strategy\Strategy;
use MerchantQuoteAgentPlugin\Strategy\StrategyResolver;
use MerchantQuoteAgentPlugin\Strategy\StrategyVersion;
use MerchantQuoteAgentPlugin\Strategy\VersionStatus;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The behaviour the per-strategy design brief is about, split out of
 * DecisionHarvestTest to keep that class under the method-count gate (the
 * same reason ImprovementRunnerBillingTest was split out of
 * ImprovementGeneratorTest): a window holding decisions from two strategies
 * must produce two groups, each carrying only its own decisions and its own
 * lineage's current prompt; and a decision naming no strategy version at all
 * cannot be attributed to any lineage and must not silently join whichever
 * group happens to exist.
 */
final class DecisionHarvestGroupingTest extends TestCase
{
    private const STRATEGY_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const VERSION_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1';

    private const STRATEGY_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const VERSION_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb1';

    public function testAWindowWithTwoStrategiesProducesTwoGroups(): void
    {
        $recordA = self::record(self::VERSION_A);
        $recordB = self::record(self::VERSION_B);
        $harvest = new DecisionHarvest($this->decisionRepository([$recordA, $recordB]), $this->twoStrategies());

        $groups = $harvest->forWindow(self::window(), 'sc-1', Context::createDefaultContext());

        self::assertCount(2, $groups);

        $byStrategy = [];

        foreach ($groups as $group) {
            $byStrategy[$group->strategyId] = $group;
        }

        self::assertArrayHasKey(self::STRATEGY_A, $byStrategy);
        self::assertArrayHasKey(self::STRATEGY_B, $byStrategy);
        self::assertCount(1, $byStrategy[self::STRATEGY_A]->decisions);
        self::assertCount(1, $byStrategy[self::STRATEGY_B]->decisions);
        self::assertSame($recordA->id, $byStrategy[self::STRATEGY_A]->decisions[0]->decisionId);
        self::assertSame($recordB->id, $byStrategy[self::STRATEGY_B]->decisions[0]->decisionId);
        self::assertSame('prompt for strategy A', $byStrategy[self::STRATEGY_A]->current->prompt);
        self::assertSame('prompt for strategy B', $byStrategy[self::STRATEGY_B]->current->prompt);
    }

    /**
     * A decision naming no version at all (predates the assignment ladder's
     * column) cannot be attributed to any lineage and must not silently join
     * whichever group happens to exist -- it is simply excluded.
     */
    public function testADecisionWithNoStrategyVersionIsExcludedFromEveryGroup(): void
    {
        $withStrategy = self::record(self::VERSION_A);
        $withoutStrategy = self::record(null);
        $harvest = new DecisionHarvest(
            $this->decisionRepository([$withStrategy, $withoutStrategy]),
            $this->twoStrategies(),
        );

        $groups = $harvest->forWindow(self::window(), 'sc-1', Context::createDefaultContext());

        self::assertCount(1, $groups);
        self::assertCount(1, $groups[0]->decisions);
        self::assertSame($withStrategy->id, $groups[0]->decisions[0]->decisionId);
    }

    private static function window(): ImprovementWindow
    {
        return (
            ImprovementWindow::due(
                null,
                new \DateTimeImmutable('2026-09-21 00:00:00'),
                \MerchantQuoteAgentPlugin\Improvement\ImprovementCadence::Daily,
            ) ?? self::fail('expected a due window')
        );
    }

    private static function record(?string $strategyVersionId): QuoteDecisionRecord
    {
        $record = new QuoteDecisionRecord();
        $id = Uuid::randomHex();
        $record->setUniqueIdentifier($id);
        $record->id = $id;
        $record->quoteId = Uuid::randomHex();
        $record->band = 'grant';
        $record->outcome = 'offered';
        $record->strategyVersionId = $strategyVersionId;

        return $record;
    }

    /** @param list<QuoteDecisionRecord> $records */
    private function decisionRepository(array $records): EntityRepository
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->method('search')
            ->willReturnCallback(
                static fn(Criteria $criteria, Context $context): EntitySearchResult => new EntitySearchResult(
                    'merchant_quote_agent_decision',
                    \count($records),
                    new EntityCollection($records),
                    null,
                    $criteria,
                    $context,
                ),
            );

        return $repository;
    }

    /**
     * Two real strategies, each with one active version, and a StrategyResolver
     * whose repositories answer BY ID -- unlike the ignore-the-Criteria
     * doubles used elsewhere in this suite, this one actually filters,
     * because both tests here depend on telling VERSION_A and VERSION_B
     * apart.
     */
    private function twoStrategies(): StrategyResolver
    {
        $strategyA = self::strategy(self::STRATEGY_A, 'Strategy A');
        $strategyB = self::strategy(self::STRATEGY_B, 'Strategy B');
        $versionA = self::version(self::VERSION_A, self::STRATEGY_A, 'prompt for strategy A');
        $versionB = self::version(self::VERSION_B, self::STRATEGY_B, 'prompt for strategy B');

        return new StrategyResolver(
            $this->byIdRepository([$strategyA, $strategyB]),
            $this->byIdRepository([$versionA, $versionB]),
        );
    }

    private static function strategy(string $id, string $name): Strategy
    {
        $strategy = new Strategy();
        $strategy->setUniqueIdentifier($id);
        $strategy->id = $id;
        $strategy->name = $name;

        return $strategy;
    }

    private static function version(string $id, string $strategyId, string $prompt): StrategyVersion
    {
        $version = new StrategyVersion();
        $version->setUniqueIdentifier($id);
        $version->id = $id;
        $version->strategyId = $strategyId;
        $version->version = 1;
        $version->prompt = $prompt;
        $version->status = VersionStatus::Active->value;

        return $version;
    }

    /**
     * A repository that actually answers by primary key or by `strategyId`
     * filter -- StrategyResolver::resolve() filters by `strategyId` (plus a
     * status this double does not bother enforcing, since StrategyResolverTest
     * already pins that query) and StrategyResolver::byVersionId() filters by
     * the primary key.
     *
     * @param list<Strategy|StrategyVersion> $entities
     */
    private function byIdRepository(array $entities): EntityRepository
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->method('search')
            ->willReturnCallback(function (Criteria $criteria, Context $context) use ($entities): EntitySearchResult {
                $matched = self::filterEntities($criteria, $entities);

                return new EntitySearchResult(
                    'test',
                    \count($matched),
                    new EntityCollection($matched),
                    null,
                    $criteria,
                    $context,
                );
            });

        return $repository;
    }

    /** @param list<Strategy|StrategyVersion> $entities @return list<Strategy|StrategyVersion> */
    private static function filterEntities(Criteria $criteria, array $entities): array
    {
        $ids = $criteria->getIds();
        $strategyId = null;

        foreach ($criteria->getFilters() as $filter) {
            if ($filter instanceof EqualsFilter && $filter->getField() === 'strategyId') {
                $strategyId = $filter->getValue();
            }
        }

        return array_values(array_filter($entities, static function (Strategy|StrategyVersion $entity) use (
            $ids,
            $strategyId,
        ): bool {
            if ($ids !== [] && !\in_array($entity->getUniqueIdentifier(), $ids, true)) {
                return false;
            }

            return $strategyId === null || !$entity instanceof StrategyVersion || $entity->strategyId === $strategyId;
        }));
    }
}
