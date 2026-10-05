<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

use Shopware\Core\Framework\Context;

/**
 * One sales channel's nightly tick: settings → window → harvest → judge →
 * replay → write, each step one call to a collaborator (see the design brief
 * for why this class is kept to orchestration and everything else lives in
 * RunSettingsResolver, DecisionHarvest, ImprovementJudge, ReplayHarness and
 * ImprovementRunWriter).
 *
 * The judge's own model call bills the run row too, not just the replay's:
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
     * @throws \Throwable rethrown as-is once the failed run is recorded, so
     *     Messenger's retry still sees it -- the same contract
     *     NegotiationPipeline::service() keeps.
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

        $all = $this->harvest->forWindow($window, $salesChannelId, $context);

        if ($all === []) {
            $this->writer->writeNoData($salesChannelId, $window, $now, $context);

            return;
        }

        $runId = $this->writer->startRunning($salesChannelId, $window, $now, $context);

        try {
            $this->evaluateAndWrite($runId, $settings, $all, $now, $context);
        } catch (\Throwable $e) {
            $this->writer->writeFailed($runId, $now, $e, $context);

            throw $e;
        }
    }

    /** @param list<HarvestedDecision> $all */
    private function evaluateAndWrite(
        string $runId,
        RunSettings $settings,
        array $all,
        \DateTimeImmutable $now,
        Context $context,
    ): void {
        [$judgePromptBefore, $judgeCompletionBefore] = $this->harness->tokensSoFar();

        $answer = $this->judge->assess(
            $settings->improvement->llm,
            DayPicture::of($all),
            $settings->agent->strategyPrompt ?? '',
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

        $sample = \array_slice($all, 0, $settings->improvement->sampleSize);
        [$tally, $proposals] = $this->harness->run($sample, $settings->agent, $answer->candidates, $context);

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
