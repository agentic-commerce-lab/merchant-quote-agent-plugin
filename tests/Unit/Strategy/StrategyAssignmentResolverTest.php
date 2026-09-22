<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Strategy;

use MerchantQuoteAgentPlugin\Bridge\QuoteRuleScopeFactory;
use MerchantQuoteAgentPlugin\Bridge\RuleScopeUnavailable;
use MerchantQuoteAgentPlugin\Strategy\ResolvedStrategy;
use MerchantQuoteAgentPlugin\Strategy\StrategyAssignment;
use MerchantQuoteAgentPlugin\Strategy\StrategyAssignmentResolver;
use MerchantQuoteAgentPlugin\Strategy\StrategyAssignmentSource;
use MerchantQuoteAgentPlugin\Strategy\StrategyResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Rule\CartRuleScope;
use Shopware\Core\Content\Rule\RuleCollection;
use Shopware\Core\Content\Rule\RuleEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Rule\Rule;
use Shopware\Core\Framework\Rule\RuleScope;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * @mago-expect lint:too-many-methods
 * One test per rung and per fall-through, and the ladder is the whole point:
 * a pin, a rule, a split, an unavailable scope and an unknown-strategy
 * escalation are five different failures and each needs its own name in the
 * output. Splitting the class would hide that they all exercise one resolver.
 */
final class StrategyAssignmentResolverTest extends TestCase
{
    private const QUOTE = '11111111111111111111111111111111';

    private const CUSTOMER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const CHANNEL = '22222222222222222222222222222222';

    private const PINNED = '3333333333333333333333333333aaaa';

    private const RULED = '3333333333333333333333333333bbbb';

    private const ARM_ONE = '3333333333333333333333333333cccc';

    private const ARM_TWO = '3333333333333333333333333333dddd';

    public function testAPinBeatsEverythingBelowIt(): void
    {
        $assigned = $this->resolver([
            $this->row('pin', self::PINNED, customerId: self::CUSTOMER),
            $this->row('split', self::ARM_ONE, weight: 100),
        ])->assign(self::QUOTE, self::CUSTOMER, self::CHANNEL, Context::createDefaultContext());

        self::assertNotNull($assigned);
        self::assertSame(self::PINNED, $assigned->strategy->versionId);
        self::assertSame(StrategyAssignmentSource::Pin, $assigned->source);
    }

    public function testAChannelPinBeatsAGlobalPin(): void
    {
        $assigned = $this->resolver([
            $this->row('pin', self::ARM_ONE, customerId: self::CUSTOMER),
            $this->row('pin', self::PINNED, customerId: self::CUSTOMER, salesChannelId: self::CHANNEL),
        ])->assign(self::QUOTE, self::CUSTOMER, self::CHANNEL, Context::createDefaultContext());

        self::assertNotNull($assigned);
        self::assertSame(self::PINNED, $assigned->strategy->versionId);
    }

    public function testAPinForAnotherCustomerIsIgnored(): void
    {
        $assigned = $this->resolver([
            $this->row('pin', self::PINNED, customerId: 'ffffffffffffffffffffffffffffffff'),
            $this->row('split', self::ARM_ONE, weight: 100),
        ])->assign(self::QUOTE, self::CUSTOMER, self::CHANNEL, Context::createDefaultContext());

        self::assertNotNull($assigned);
        self::assertSame(StrategyAssignmentSource::Split, $assigned->source);
    }

    public function testTheHighestPriorityMatchingRuleWins(): void
    {
        $assigned = $this->resolver([
            $this->row('rule', self::ARM_ONE, ruleId: 'r1'),
            $this->row('rule', self::RULED, ruleId: 'r2'),
        ], rules: [
            $this->rule('r1', priority: 1, matches: true),
            $this->rule('r2', priority: 9, matches: true),
        ])->assign(self::QUOTE, self::CUSTOMER, self::CHANNEL, Context::createDefaultContext());

        self::assertNotNull($assigned);
        self::assertSame(self::RULED, $assigned->strategy->versionId);
        self::assertSame(StrategyAssignmentSource::Rule, $assigned->source);
    }

    public function testANonMatchingRuleFallsThroughToTheSplit(): void
    {
        $assigned = $this->resolver([
            $this->row('rule', self::RULED, ruleId: 'r1'),
            $this->row('split', self::ARM_ONE, weight: 100),
        ], rules: [$this->rule('r1', priority: 1, matches: false)])->assign(
            self::QUOTE,
            self::CUSTOMER,
            self::CHANNEL,
            Context::createDefaultContext(),
        );

        self::assertNotNull($assigned);
        self::assertSame(self::ARM_ONE, $assigned->strategy->versionId);
        self::assertSame(StrategyAssignmentSource::Split, $assigned->source);
    }

    /**
     * The whole rung, never part of it. A half-evaluated list would pick a
     * strategy by accident of ordering, which is worse than picking none.
     */
    public function testAnUnavailableScopeSkipsTheEntireRuleRung(): void
    {
        $assigned = $this->resolver(
            [$this->row('rule', self::RULED, ruleId: 'r1'), $this->row('split', self::ARM_ONE, weight: 100)],
            rules: [$this->rule('r1', priority: 1, matches: true)],
            scopeFails: true,
        )->assign(self::QUOTE, self::CUSTOMER, self::CHANNEL, Context::createDefaultContext());

        self::assertNotNull($assigned);
        self::assertSame(StrategyAssignmentSource::Split, $assigned->source);
    }

    public function testAZeroWeightArmIsNeverChosen(): void
    {
        $assigned = $this->resolver([
            $this->row('split', self::ARM_ONE, weight: 0),
            $this->row('split', self::ARM_TWO, weight: 100),
        ])->assign(self::QUOTE, self::CUSTOMER, self::CHANNEL, Context::createDefaultContext());

        self::assertNotNull($assigned);
        self::assertSame(self::ARM_TWO, $assigned->strategy->versionId);
    }

    public function testWeightsSummingToZeroFallThrough(): void
    {
        $assigned = $this->resolver([
            $this->row('split', self::ARM_ONE, weight: 0),
            $this->row('split', self::ARM_TWO, weight: 0),
        ])->assign(self::QUOTE, self::CUSTOMER, self::CHANNEL, Context::createDefaultContext());

        self::assertNull($assigned);
    }

    public function testWeightsNeedNotSumToOneHundred(): void
    {
        $assigned = $this->resolver([
            $this->row('split', self::ARM_ONE, weight: 1),
            $this->row('split', self::ARM_TWO, weight: 4),
        ])->assign(self::QUOTE, self::CUSTOMER, self::CHANNEL, Context::createDefaultContext());

        self::assertNotNull($assigned);
        self::assertContains($assigned->strategy->versionId, [self::ARM_ONE, self::ARM_TWO]);
    }

    public function testNoRowsAtAllMeansNoAssignment(): void
    {
        $assigned = $this->resolver([])->assign(
            self::QUOTE,
            self::CUSTOMER,
            self::CHANNEL,
            Context::createDefaultContext(),
        );

        self::assertNull($assigned);
    }

    /**
     * Rung 4 is the configuration key and is refused loudly when it dangles.
     * An assignment row that dangles is the merchant's own configuration too,
     * made in our own UI, so it must not degrade to a different posture.
     */
    public function testAnArchivedTargetSurfacesAsUnknownStrategy(): void
    {
        $this->expectException(\MerchantQuoteAgentPlugin\Strategy\UnknownStrategy::class);

        $this->resolver([$this->row('pin', self::PINNED, customerId: self::CUSTOMER)], archived: true)->assign(
            self::QUOTE,
            self::CUSTOMER,
            self::CHANNEL,
            Context::createDefaultContext(),
        );
    }

    /** @param list<StrategyAssignment> $rows @param list<RuleEntity> $rules */
    private function resolver(
        array $rows,
        array $rules = [],
        bool $scopeFails = false,
        bool $archived = false,
    ): StrategyAssignmentResolver {
        $strategies = $this->createMock(StrategyResolver::class);
        $strategies
            ->method('resolve')
            ->willReturnCallback(static function (string $strategyId) use ($archived): ResolvedStrategy {
                if ($archived) {
                    throw \MerchantQuoteAgentPlugin\Strategy\UnknownStrategy::archived($strategyId);
                }

                return new ResolvedStrategy($strategyId, 'prompt for ' . $strategyId);
            });

        $scopes = $this->createMock(QuoteRuleScopeFactory::class);
        if ($scopeFails) {
            $scopes->method('forQuote')->willThrowException(new RuleScopeUnavailable('no address'));
        } else {
            $scopes
                ->method('forQuote')
                ->willReturn(new CartRuleScope(new Cart('token'), $this->createMock(SalesChannelContext::class)));
        }

        return new StrategyAssignmentResolver(
            $this->repository($rows, 'merchant_quote_agent_strategy_assignment'),
            $this->repository($rules, 'rule'),
            $strategies,
            $scopes,
            new NullLogger(),
        );
    }

    /**
     * @mago-expect lint:excessive-parameter-list
     * One optional parameter per column `StrategyAssignment` has beyond `id`
     * and `kind`; every test needs a different subset, and named arguments at
     * each call site keep it readable despite the count.
     */
    private function row(
        string $kind,
        string $strategyId,
        ?string $customerId = null,
        ?string $ruleId = null,
        ?int $weight = null,
        ?string $salesChannelId = null,
    ): StrategyAssignment {
        $row = new StrategyAssignment();
        $row->setUniqueIdentifier($kind . $strategyId . ($ruleId ?? ''));
        $row->id = $kind . $strategyId;
        $row->kind = $kind;
        $row->strategyId = $strategyId;
        $row->customerId = $customerId;
        $row->ruleId = $ruleId;
        $row->weight = $weight;
        $row->salesChannelId = $salesChannelId;

        return $row;
    }

    private function rule(string $id, int $priority, bool $matches): RuleEntity
    {
        $rule = new RuleEntity();
        $rule->setUniqueIdentifier($id);
        $rule->setId($id);
        $rule->setPriority($priority);
        $rule->setPayload(new class($matches) extends Rule {
            final public const RULE_NAME = 'strategyAssignmentResolverTest';

            public function __construct(
                private readonly bool $matches,
            ) {
                parent::__construct();
            }

            public function match(RuleScope $scope): bool
            {
                return $this->matches;
            }

            public function getConstraints(): array
            {
                return [];
            }
        });

        return $rule;
    }

    /** @param list<object> $entities */
    private function repository(array $entities, string $definition): EntityRepository
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->method('search')
            ->willReturnCallback(
                static fn(Criteria $criteria, Context $context): EntitySearchResult => new EntitySearchResult(
                    $definition,
                    \count($entities),
                    $definition === 'rule' ? new RuleCollection($entities) : new EntityCollection($entities),
                    null,
                    $criteria,
                    $context,
                ),
            );

        return $repository;
    }
}
