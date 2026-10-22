<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

use MerchantQuoteAgentPlugin\Strategy\ResolvedStrategy;

/**
 * One lineage's slice of a window: every harvested decision that named a
 * version of THIS strategy, grouped by the version's `strategyId` rather than
 * by the version itself -- see DecisionHarvest -- so a merchant who edited a
 * prompt mid-window still sees one group, not one per edit.
 *
 * `current` is that lineage's live prompt right now (StrategyResolver::resolve(),
 * the same active-status read production negotiation uses), resolved once per
 * group rather than once per decision: it is what ImprovementJudge shows the
 * model as "the current strategy section" and what a candidate is appended
 * to. It is deliberately NOT what any individual decision in `decisions` was
 * actually sent -- that is `HarvestedDecision::$strategyVersionId`, resolved
 * per decision by ReplaySubjectResolver for the control arm. The two can
 * differ within one group precisely because of the mid-window edit this
 * class is grouped to tolerate.
 */
final readonly class StrategyGroup
{
    /** @param list<HarvestedDecision> $decisions */
    public function __construct(
        public string $strategyId,
        public ResolvedStrategy $current,
        public array $decisions,
    ) {}
}
