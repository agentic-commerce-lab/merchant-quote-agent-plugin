<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

use MerchantQuoteAgentPlugin\Strategy\StrategyAssignmentSource;
use Shopware\Core\Framework\Context;

/**
 * One sales channel's nightly tick: settings → window → harvest+group → one
 * judge/replay/write cycle PER STRATEGY the window's decisions belong to (see
 * DecisionHarvest and StrategyGroup for why the window is grouped by lineage
 * before any of that happens). Each step past grouping is one call to a
 * collaborator (see the design brief for why this class is kept to
 * orchestration and everything else lives in RunSettingsResolver,
 * DecisionHarvest, ImprovementJudge, ReplayHarness and ImprovementRunWriter).
 *
 * Every group gets its own run row and its own attempt: one lineage's model
 * outage must not stop another lineage in the same channel from being
 * evaluated tonight -- the whole point of grouping by strategy is that the B
 * arm gets improved too, so it cannot be held hostage by a problem with A.
 * If any group fails, the LAST failure is rethrown once every group has been
 * attempted, so Messenger's retry still sees it -- with exactly one group
 * (today's common case while few channels run more than one strategy) this
 * degenerates to the old single-run contract exactly.
 *
 * The judge's own model call bills its run row too, not just the replay's:
 * ImprovementJudge is wired to the run's PRIVATE ModelPlatform/DecisionRecorder
 * pair (see that class's own docblock), the same TallyingDecisionWriter
 * ReplayHarness reports through, so evaluateAndWrite() reads
 * ReplayHarness::tokensSoFar() before and after the judge call to bill that
 * delta separately from the replay's own -- which still starts its OWN
 * before/after measurement only once the judge call has finished, so
 * ReplayHarness's returned RunTally stays exactly what the replay itself
 * cost.
 */
final readonly class ImprovementRunner
{
    public function __construct(
        private RunSettingsResolver $settings,
        private DecisionHarvest $harvest,
        private ImprovementJudge $judge,
        private ReplayHarness $harness,
        private ImprovementRunWriter $writer,
    ) {}

    /**
     * @throws \Throwable rethrown as-is once every group has been attempted
     *     and the failed run(s) recorded, so Messenger's retry still sees it
     *     -- the same contract NegotiationPipeline::service() keeps.
     */
    public function run(?string $salesChannelId, \DateTimeImmutable $now): void
    {
        $settings = $this->settings->resolve($salesChannelId);

        if ($settings === null) {
            return;
        }

        $context = Context::createDefaultContext();
        $window = ImprovementWindow::due(
            $this->writer->lastCompletedAt($salesChannelId, $context),
            $now,
            $settings->improvement->cadence,
        );

        if ($window === null) {
            return;
        }

        $groups = $this->harvest->forWindow($window, $salesChannelId, $context);

        if ($groups === []) {
            $this->writer->writeNoData($salesChannelId, $window, $now, $context);

            return;
        }

        $this->runGroups(new TickContext($salesChannelId, $window, $now), $settings, $groups, $context);
    }

    /**
     * @param list<StrategyGroup> $groups
     *
     * @throws \Throwable the last group's failure, once every group has been attempted
     */
    private function runGroups(TickContext $tick, RunSettings $settings, array $groups, Context $context): void
    {
        $failure = null;

        foreach ($groups as $group) {
            try {
                $this->evaluateGroup($tick, $settings, $group, $context);
            } catch (\Throwable $e) {
                $failure = $e;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    /** @throws \Throwable rethrown once writeFailed() has recorded it on this group's own row */
    private function evaluateGroup(
        TickContext $tick,
        RunSettings $settings,
        StrategyGroup $group,
        Context $context,
    ): void {
        $runId = $this->writer->startRunning(
            $tick->salesChannelId,
            $group->strategyId,
            $tick->window,
            $tick->now,
            $context,
        );

        try {
            $this->evaluateAndWrite($runId, $settings, $group, $tick->now, $context);
        } catch (\Throwable $e) {
            $this->writer->writeFailed($runId, $tick->now, $e, $context);

            throw $e;
        }
    }

    private function evaluateAndWrite(
        string $runId,
        RunSettings $settings,
        StrategyGroup $group,
        \DateTimeImmutable $now,
        Context $context,
    ): void {
        [$judgePromptBefore, $judgeCompletionBefore] = $this->harness->tokensSoFar();

        $answer = $this->judge->assess(
            $settings->improvement->llm,
            DayPicture::of($group->decisions),
            $group->current->prompt,
            $settings->improvement->candidates,
        );

        [$judgePromptAfter, $judgeCompletionAfter] = $this->harness->tokensSoFar();
        $judgePromptTokens = $judgePromptAfter - $judgePromptBefore;
        $judgeCompletionTokens = $judgeCompletionAfter - $judgeCompletionBefore;

        if ($answer === null) {
            $this->writer->writeCompleted(
                $runId,
                RunOutcome::empty($now, $settings->improvement->llm->model, $judgePromptTokens, $judgeCompletionTokens),
                $context,
            );

            return;
        }

        $sample = \array_slice($group->decisions, 0, $settings->improvement->sampleSize);
        // The group's own current version stands in for "the channel
        // default" every other arm shares its policy/model access with --
        // this is not a real ladder decision, so the source is an inert
        // placeholder: nothing downstream of ReplayHarness reads
        // $control->strategyAssignmentSource.
        $control = $settings->agent->withStrategy($group->current, StrategyAssignmentSource::Config);
        [$tally, $proposals] = $this->harness->run($sample, $control, $answer->candidates, $context);

        $this->writer->writeCompleted(
            $runId,
            new RunOutcome(
                finishedAt: $now,
                tally: new RunTally(
                    $tally->sampled,
                    $tally->skipped,
                    $tally->promptTokens + $judgePromptTokens,
                    $tally->completionTokens + $judgeCompletionTokens,
                ),
                model: $settings->improvement->llm->model,
                findings: $answer->findings,
                proposals: $proposals,
            ),
            $context,
        );
    }
}
