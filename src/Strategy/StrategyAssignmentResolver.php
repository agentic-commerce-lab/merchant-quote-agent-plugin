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
 * Both rules aggregate per class against a threshold of 10. Each rung
 * re-filters and re-sorts its own rows in PHP rather than trusting that a
 * Criteria was applied exactly as asked -- a wrong winner on any rung is a
 * wrong negotiating posture for a real quote -- and that one guard clause per
 * invariant, repeated across three independent rungs, is what pushes the
 * class over the threshold.
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
     * pin win; the earliest `createdAt` breaks the remaining tie -- two GLOBAL
     * pins, or two pins for the same channel -- deterministically rather than
     * by whatever order the repository happens to return them in.
     *
     * The Criteria filters are also re-checked in PHP against every row the
     * repository hands back, rather than trusting `->first()` on a criteria
     * the repository claims to have applied: this is the customer's pin, a
     * wrong one is a wrong negotiating posture on the merchant's behalf, and
     * that is worth one extra loop over what is at most a handful of rows.
     */
    private function pinned(string $customerId, string $salesChannelId, Context $context): ?string
    {
        if ($customerId === '') {
            return null;
        }

        $criteria = $this->scoped(StrategyAssignmentSource::Pin->value, $salesChannelId);
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));

        /** @var list<StrategyAssignment> $candidates */
        $candidates = [];

        foreach ($this->assignments->search($criteria, $context)->getElements() as $row) {
            if (
                $row instanceof StrategyAssignment
                && $row->kind === StrategyAssignmentSource::Pin->value
                && $row->customerId === $customerId
                && ($row->salesChannelId === null || $row->salesChannelId === $salesChannelId)
            ) {
                $candidates[] = $row;
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static function (StrategyAssignment $a, StrategyAssignment $b): int {
            $byChannel = ($a->salesChannelId === null ? 1 : 0) <=> ($b->salesChannelId === null ? 1 : 0);

            if ($byChannel !== 0) {
                return $byChannel;
            }

            return ($a->getCreatedAt()?->getTimestamp() ?? 0) <=> ($b->getCreatedAt()?->getTimestamp() ?? 0);
        });

        return $candidates[0]->strategyId;
    }

    /**
     * Rows are re-filtered by kind in PHP for the same reason as `pinned()`,
     * and the matching rules are re-sorted by priority in PHP rather than
     * trusting the order the repository returns them in: a wrong winner here
     * is a wrong negotiating posture for every quote a matching customer
     * raises, not just this one.
     */
    private function ruled(string $quoteId, string $salesChannelId, Context $context): ?string
    {
        $rows = $this->assignments
            ->search($this->scoped(StrategyAssignmentSource::Rule->value, $salesChannelId), $context)
            ->getElements();

        /** @var array<string, string> $byRule */
        $byRule = [];

        foreach ($rows as $row) {
            if (
                $row instanceof StrategyAssignment
                && $row->kind === StrategyAssignmentSource::Rule->value
                && $row->ruleId !== null
                && ($row->salesChannelId === null || $row->salesChannelId === $salesChannelId)
            ) {
                $byRule[$row->ruleId] = $row->strategyId;
            }
        }

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

        /** @var list<RuleEntity> $rules */
        $rules = [];

        foreach ($this->rules->search($criteria, $context)->getElements() as $rule) {
            if ($rule instanceof RuleEntity) {
                $rules[] = $rule;
            }
        }

        usort($rules, static fn(RuleEntity $a, RuleEntity $b): int => $b->getPriority() <=> $a->getPriority());

        foreach ($rules as $rule) {
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
            if (
                $row instanceof StrategyAssignment
                && $row->kind === StrategyAssignmentSource::Split->value
                && ($row->salesChannelId === null || $row->salesChannelId === $salesChannelId)
                && $row->weight !== null
                && $row->weight > 0
            ) {
                $arms[] = $row;
                $total += $row->weight;
            }
        }

        if ($total === 0) {
            return null;
        }

        usort($arms, static fn(StrategyAssignment $a, StrategyAssignment $b): int => $a->strategyId <=> $b->strategyId);

        $target = (int) ((SplitBucket::of($customerId, $salesChannelId) * $total) / 10000);
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
