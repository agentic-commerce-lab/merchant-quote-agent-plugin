<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Strategy;

use MerchantQuoteAgentPlugin\Bridge\QuoteRuleScopeFactory;
use MerchantQuoteAgentPlugin\Bridge\RuleScopeUnavailable;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Rule\RuleEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Rule\Rule;

/**
 * Which strategy this negotiation should use, from the assignment ladder.
 *
 * Four rungs, in this order: a customer pin, the highest-priority matching
 * rule, a weighted split, and -- by returning null -- the sales-channel
 * configuration key the caller already resolved. Each rung resolves
 * independently, so a GLOBAL pin beats a channel-specific rule: the ladder
 * order is the precedence, the sales channel is only a filter within a rung.
 *
 * Uncached, once per pass, alongside the reads ServicingPreflight already
 * makes. The rule rung is lazy -- no quote read, no context restore and no
 * cart conversion happen unless rung 2 actually has rows.
 *
 * A rule that cannot be evaluated skips the WHOLE rung and logs a warning: a
 * missing shipping address is a data condition, not a misconfiguration, and
 * escalating every such quote to a human is worse for the merchant than
 * servicing it on the next rung. A half-evaluated list would instead pick by
 * accident of ordering. Because that fall-through is invisible in the
 * negotiation itself, the chosen rung is recorded on the decision row.
 *
 * An assignment naming a missing or archived strategy is the opposite case and
 * is NOT swallowed: UnknownStrategy propagates, ServicingPreflight turns it
 * into the escalation it already turns a dangling config key into. That
 * dangling row is configuration the merchant made in our own UI, and silently
 * negotiating with a different posture than they configured is the failure
 * QuoteAgentSettingsReader exists to refuse.
 *
 * Neither `final` nor `readonly` at class level, unlike almost everything else
 * here, and for one reason each. Not final: ServicingSettingsFixture extends it
 * with a counting double, and PHPUnit cannot double a final class. Not readonly
 * at class level: PHP forbids a non-readonly child of a readonly class, and a
 * readonly child could not hold the mutable call counter that double exists
 * for. Every property below is still `readonly` individually, so instances are
 * as immutable as they would have been. StrategyResolver makes the same trade
 * and its docblock says so.
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * Both rules aggregate per class against a threshold of 10. Four independent
 * rungs, each with its own guard clauses, plus the rule rung's own
 * try/catch around an external boundary (QuoteRuleScopeFactory), add up to
 * more branches than any one of them has alone. No method here is
 * individually complex; the count is the sum across the ladder.
 */
class StrategyAssignmentResolver
{
    public function __construct(
        private readonly EntityRepository $assignments,
        private readonly EntityRepository $rules,
        private readonly StrategyResolver $strategies,
        private readonly QuoteRuleScopeFactory $scopes,
        private readonly LoggerInterface $logger,
    ) {}

    /** @throws UnknownStrategy */
    public function assign(
        string $quoteId,
        string $customerId,
        string $salesChannelId,
        Context $context,
    ): ?AssignedStrategy {
        $strategyId = $this->pinned($customerId, $salesChannelId, $context);

        if ($strategyId !== null) {
            return $this->resolve($strategyId, StrategyAssignmentSource::Pin, $context);
        }

        $strategyId = $this->ruled($quoteId, $salesChannelId, $context);

        if ($strategyId !== null) {
            return $this->resolve($strategyId, StrategyAssignmentSource::Rule, $context);
        }

        $strategyId = $this->split($customerId, $salesChannelId, $context);

        if ($strategyId !== null) {
            return $this->resolve($strategyId, StrategyAssignmentSource::Split, $context);
        }

        return null;
    }

    /**
     * MySQL treats NULLs as distinct in a unique index, so two GLOBAL pins for
     * one customer are accepted by the schema (see the migration's own note on
     * this). Sorting a non-null sales channel first makes the channel-specific
     * pin win -- DESCENDING on a nullable column puts non-nulls first in
     * MySQL -- and the earliest `createdAt` breaks the remaining tie
     * deterministically rather than by whatever order the repository happens
     * to return rows in. Both are expressed in the Criteria, not re-derived
     * in PHP: the query is the one source of truth for what "wins" means
     * here, and CriteriaFilter (see StrategyAssignmentResolverTest) is what
     * lets a unit test prove that without a real database.
     */
    private function pinned(string $customerId, string $salesChannelId, Context $context): ?string
    {
        if ($customerId === '') {
            return null;
        }

        $criteria = $this->scoped(StrategyAssignmentSource::Pin->value, $salesChannelId);
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));
        $criteria->addSorting(new FieldSorting('salesChannelId', FieldSorting::DESCENDING));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::ASCENDING));
        $criteria->setLimit(1);

        $row = $this->assignments->search($criteria, $context)->first();

        return $row instanceof StrategyAssignment ? $row->strategyId : null;
    }

    /**
     * A rule bound both globally and on this channel resolves to the
     * channel's strategy -- built from two maps, global filled first and
     * channel second, merged with the channel map winning, so the result
     * never depends on the order `scoped()`'s rows come back in (the search
     * itself carries no sorting). Two bindings of the SAME rule at the same
     * scope are still resolved by whichever one lands in the map last: that
     * is genuinely ambiguous configuration, and the admin UI refuses to
     * create it, so this resolver does not need to.
     */
    private function ruled(string $quoteId, string $salesChannelId, Context $context): ?string
    {
        $rows = $this->assignments
            ->search($this->scoped(StrategyAssignmentSource::Rule->value, $salesChannelId), $context)
            ->getElements();

        /** @var array<string, string> $byRuleGlobal */
        $byRuleGlobal = [];
        /** @var array<string, string> $byRuleChannel */
        $byRuleChannel = [];

        foreach ($rows as $row) {
            if (!$row instanceof StrategyAssignment || $row->ruleId === null) {
                continue;
            }

            if ($row->salesChannelId === null) {
                $byRuleGlobal[$row->ruleId] = $row->strategyId;
            } else {
                $byRuleChannel[$row->ruleId] = $row->strategyId;
            }
        }

        $byRule = array_merge($byRuleGlobal, $byRuleChannel);

        if ($byRule === []) {
            return null;
        }

        try {
            $scope = $this->scopes->forQuote($quoteId, $context);
        } catch (RuleScopeUnavailable $e) {
            $this->logger->warning('This quote could not be turned into a rule scope, so every rule-based strategy '
            . 'assignment was skipped for it and the next rung of the ladder decided instead.', [
                'quoteId' => $quoteId,
                'salesChannelId' => $salesChannelId,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }

        $criteria = new Criteria(array_keys($byRule));
        $criteria->addSorting(new FieldSorting('priority', FieldSorting::DESCENDING));

        foreach ($this->rules->search($criteria, $context)->getElements() as $rule) {
            if (!$rule instanceof RuleEntity) {
                continue;
            }

            // getPayload(): Rule|string|null -- a serialized-but-not-yet-
            // hydrated payload is a real possibility in core's own type, not
            // a row this ladder needs to filter out itself.
            $payload = $rule->getPayload();

            if ($payload instanceof Rule && $payload->match($scope) === true) {
                return $byRule[$rule->getId()] ?? null;
            }
        }

        return null;
    }

    /**
     * Cumulative weights over a 10000-bucket hash, so weights need not sum to
     * 100 -- 1 and 4 mean the same as 20 and 80. Ordered by strategy id rather
     * than by insertion, so re-creating an arm does not reshuffle the others.
     *
     * Changing any weight DOES reshuffle which companies land in which arm.
     * That is inherent to a stateless split and is why the dashboard reports
     * N per strategy rather than assuming a stable cohort.
     */
    private function split(string $customerId, string $salesChannelId, Context $context): ?string
    {
        $criteria = $this->scoped(StrategyAssignmentSource::Split->value, $salesChannelId);
        $criteria->addSorting(new FieldSorting('strategyId', FieldSorting::ASCENDING));

        /** @var list<StrategyAssignment> $arms */
        $arms = [];
        $total = 0;

        foreach ($this->assignments->search($criteria, $context)->getElements() as $row) {
            // weight > 0 is domain logic, not a re-filter of what the
            // Criteria already scoped: a configured zero-weight arm is a
            // live row (kind, channel and all) that this rung must still
            // never pick, per the brief.
            if ($row instanceof StrategyAssignment && $row->weight !== null && $row->weight > 0) {
                $arms[] = $row;
                $total += $row->weight;
            }
        }

        if ($total === 0) {
            return null;
        }

        /**
         * @mago-expect analysis:unhandled-thrown-type
         * @mago-expect analysis:unhandled-thrown-type
         * intdiv() can throw DivisionByZeroError for a zero divisor or
         * ArithmeticError for PHP_INT_MIN / -1; neither is reachable here.
         * The divisor is the literal 10000. The dividend is
         * SplitBucket::of()'s contractually-bounded [0, 9999] result times
         * $total (guarded non-zero just above), which cannot approach
         * PHP_INT_MIN on any platform this plugin runs on.
         */
        $target = intdiv(SplitBucket::of($customerId, $salesChannelId) * $total, 10000);
        $seen = 0;
        $last = null;

        foreach ($arms as $arm) {
            $last = $arm;
            $seen += (int) $arm->weight;

            if ($target < $seen) {
                return $arm->strategyId;
            }
        }

        // Unreachable in practice -- $target is always < $total for bucket <
        // 10000 -- but kept as a safety net rather than an assertion, since a
        // wrong assumption here would otherwise throw on a real negotiation.
        return $last?->strategyId;
    }

    /**
     * A row naming this channel or naming none. Both are read in one query and
     * separated by the caller's own sorting, rather than by a second query for
     * the global fallback.
     */
    private function scoped(string $kind, string $salesChannelId): Criteria
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('kind', $kind));
        $criteria->addFilter(new EqualsAnyFilter('salesChannelId', [$salesChannelId, null]));

        return $criteria;
    }

    /** @throws UnknownStrategy */
    private function resolve(string $strategyId, StrategyAssignmentSource $source, Context $context): AssignedStrategy
    {
        return new AssignedStrategy($this->strategies->resolve($strategyId, $context), $source);
    }
}
