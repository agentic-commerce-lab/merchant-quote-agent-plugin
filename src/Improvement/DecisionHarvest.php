<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use MerchantQuoteAgentPlugin\Strategy\ResolvedStrategy;
use MerchantQuoteAgentPlugin\Strategy\StrategyResolver;
use MerchantQuoteAgentPlugin\Strategy\UnknownStrategy;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * One window's worth of decisions, read whole -- not limited to the replay
 * sample size -- and grouped by strategy LINEAGE (see StrategyGroup).
 * DayPicture::of() is built from every decision in a group, because an
 * aggregate over 20 of 300 decisions is not the period's picture;
 * ImprovementRunner is what slices the replay sample off the front of each
 * group's decision list.
 *
 * Grouping is a lookup, not an attribution: every decision row already names
 * exactly one `strategy_version_id`, so this class never re-derives which
 * strategy served a quote (see the per-strategy design brief -- the admin
 * owns quote-level attribution in `strategy-measures.ts` and a second rule
 * that disagreed with it would be worse than the bug this fixes). A decision
 * with no recorded `strategyVersionId` -- one written before the assignment
 * ladder shipped that column, or naming a version this read can no longer
 * find -- cannot be attributed to any lineage and is excluded from every
 * group, never counted in `skipped` (that count belongs to the LATER, sampled
 * stage; see ReplayHarness).
 *
 * The range is half-open, `$window->from` inclusive and `$window->to`
 * exclusive, the same convention ImprovementWindow and the decision export
 * already use, so two consecutive runs can never read one decision twice.
 */
final readonly class DecisionHarvest
{
    public function __construct(
        private EntityRepository $decisions,
        private StrategyResolver $strategies,
    ) {}

    /** @return list<StrategyGroup> */
    public function forWindow(ImprovementWindow $window, ?string $salesChannelId, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new RangeFilter('createdAt', [
            RangeFilter::GTE => $window->from->format(\DateTimeInterface::ATOM),
            RangeFilter::LT => $window->to->format(\DateTimeInterface::ATOM),
        ]));
        $criteria->addFilter(new EqualsFilter('salesChannelId', $salesChannelId));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));

        /** @var array<string, list<HarvestedDecision>> $byStrategy */
        $byStrategy = [];
        /** @var array<string, ?string> $strategyIdCache */
        $strategyIdCache = [];

        foreach ($this->decisions->search($criteria, $context)->getEntities() as $record) {
            if (!$record instanceof QuoteDecisionRecord) {
                continue;
            }

            $decision = self::harvest($record);
            $strategyId = $this->strategyIdFor($decision->strategyVersionId, $context, $strategyIdCache);

            if ($strategyId !== null) {
                $byStrategy[$strategyId][] = $decision;
            }
        }

        return $this->groups($byStrategy, $context);
    }

    /**
     * @param array<string, list<HarvestedDecision>> $byStrategy
     *
     * @return list<StrategyGroup>
     */
    private function groups(array $byStrategy, Context $context): array
    {
        $groups = [];

        foreach ($byStrategy as $strategyId => $decisions) {
            $current = $this->current($strategyId, $context);

            if ($current !== null) {
                $groups[] = new StrategyGroup($strategyId, $current, $decisions);
            }
        }

        return $groups;
    }

    /**
     * The lineage's live prompt right now. Null -- dropping the whole group
     * rather than failing the tick -- when the strategy has since become
     * unreadable (archived, or deleted along with every version): one
     * lineage going bad must not stop every OTHER lineage in this channel
     * from being evaluated tonight, the same reasoning RunSettingsResolver
     * already applies to a misconfigured channel.
     */
    private function current(string $strategyId, Context $context): ?ResolvedStrategy
    {
        try {
            return $this->strategies->resolve($strategyId, $context);
        } catch (UnknownStrategy) {
            return null;
        }
    }

    /**
     * Resolved once per distinct version id per call, not once per decision:
     * most decisions in a window share a handful of versions.
     *
     * @param array<string, ?string> $cache
     */
    private function strategyIdFor(?string $versionId, Context $context, array &$cache): ?string
    {
        if ($versionId === null) {
            return null;
        }

        if (!\array_key_exists($versionId, $cache)) {
            $cache[$versionId] = $this->strategies->byVersionId($versionId, $context)?->strategyId;
        }

        return $cache[$versionId];
    }

    private static function harvest(QuoteDecisionRecord $record): HarvestedDecision
    {
        return new HarvestedDecision(
            $record->id,
            $record->quoteId,
            new DecisionClassification(
                $record->band,
                $record->outcome,
                $record->escalationReason,
                $record->terminalState,
            ),
            new DecisionDiscount($record->discountPercentGranted, $record->maxDiscountPercent),
            new DecisionExtraction(
                $record->interpretedAsks,
                $record->extractPromptHash,
                $record->strategyVersionId,
                $record->strategyAssignmentSource,
            ),
        );
    }
}
