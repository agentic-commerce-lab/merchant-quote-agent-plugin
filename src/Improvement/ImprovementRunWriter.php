<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Every write (and the one read) against `merchant_quote_agent_improvement_run`.
 *
 * One row per (sales channel, strategy) per tick, not one per channel: a
 * window's decisions are grouped by lineage before ImprovementRunner ever
 * reaches this class (see DecisionHarvest, StrategyGroup), and each group
 * gets its own row via `strategyId` so the administration can list and
 * compare arms per strategy, not just per channel. `writeNoData()` is the one
 * exception -- an empty or fully-unattributed window never reaches grouping
 * at all, so its one row carries no `strategyId`.
 *
 * The `running` row is created by startRunning() BEFORE any model work, so a
 * crash leaves evidence; writeFailed() and writeCompleted() both UPDATE that
 * same row rather than writing a second one. writeNoData() is the one
 * exception: an empty window never becomes `running` at all, so it writes its
 * one `no_data` row directly (see RunStatus's own docblock for why that
 * status exists).
 *
 * lastCompletedAt() stays scoped to the CHANNEL alone, ignoring `strategyId`:
 * the window a tick reads is per channel (see DecisionHarvest), computed
 * BEFORE grouping even runs, so it must be answerable before any group's
 * strategyId is known.
 *
 * The candidate half of a completed run -- one `merchant_quote_agent_strategy_version`
 * row per proposal -- is StrategyProposalWriter's, a separate table with a
 * separate writer, kept out of this class so it stays under the method-count
 * gate.
 */
final readonly class ImprovementRunWriter
{
    public function __construct(
        private EntityRepository $runs,
        private StrategyProposalWriter $proposals,
    ) {}

    /** The window's start for the next tick: only a COMPLETED run moves it forward. */
    public function lastCompletedAt(?string $salesChannelId, Context $context): ?\DateTimeImmutable
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('salesChannelId', $salesChannelId));
        $criteria->addFilter(new EqualsFilter('status', RunStatus::Completed->value));
        $criteria->addSorting(new FieldSorting('finishedAt', FieldSorting::DESCENDING));
        $criteria->setLimit(1);

        $run = $this->runs->search($criteria, $context)->getEntities()->first();

        return $run instanceof ImprovementRun ? $run->finishedAt : null;
    }

    public function writeNoData(
        ?string $salesChannelId,
        ImprovementWindow $window,
        \DateTimeImmutable $now,
        Context $context,
    ): void {
        $this->runs->create([[
            'id' => Uuid::randomHex(),
            'salesChannelId' => $salesChannelId,
            'windowFrom' => $window->from,
            'windowTo' => $window->to,
            'startedAt' => $now,
            'finishedAt' => $now,
            'status' => RunStatus::NoData->value,
            'sampled' => 0,
            'skipped' => 0,
        ]], $context);
    }

    /** @return string the new run's id */
    public function startRunning(
        ?string $salesChannelId,
        string $strategyId,
        ImprovementWindow $window,
        \DateTimeImmutable $now,
        Context $context,
    ): string {
        $id = Uuid::randomHex();

        $this->runs->create([[
            'id' => $id,
            'salesChannelId' => $salesChannelId,
            'strategyId' => $strategyId,
            'windowFrom' => $window->from,
            'windowTo' => $window->to,
            'startedAt' => $now,
            'status' => RunStatus::Running->value,
            'sampled' => 0,
            'skipped' => 0,
        ]], $context);

        return $id;
    }

    public function writeFailed(
        string $runId,
        \DateTimeImmutable $finishedAt,
        \Throwable $error,
        Context $context,
    ): void {
        $this->runs->update([[
            'id' => $runId,
            'finishedAt' => $finishedAt,
            'status' => RunStatus::Failed->value,
            'error' => $error::class . ': ' . $error->getMessage(),
        ]], $context);
    }

    public function writeCompleted(string $runId, RunOutcome $outcome, Context $context): void
    {
        $this->runs->update([[
            'id' => $runId,
            'finishedAt' => $outcome->finishedAt,
            'status' => RunStatus::Completed->value,
            'sampled' => $outcome->tally->sampled,
            'skipped' => $outcome->tally->skipped,
            'findings' => self::findingsArray($outcome->findings),
            'model' => $outcome->model,
            'promptTokens' => $outcome->tally->promptTokens,
            'completionTokens' => $outcome->tally->completionTokens,
        ]], $context);

        $this->proposals->write($runId, $outcome, $context);
    }

    /**
     * @param list<JudgeFinding> $findings
     *
     * @return list<array{pattern: string, count: int, evidence: list<string>}>
     */
    private static function findingsArray(array $findings): array
    {
        return array_map(static fn(JudgeFinding $finding): array => [
            'pattern' => $finding->pattern,
            'count' => $finding->count,
            'evidence' => $finding->evidence,
        ], $findings);
    }
}
