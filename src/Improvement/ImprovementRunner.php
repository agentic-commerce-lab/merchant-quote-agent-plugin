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
 * The judge's own model call is never tallied onto the run: ModelPlatform
 * records tokens through whichever DecisionRecorder it was built with, and
 * ImprovementJudge is wired to the container's SHARED ModelPlatform, whose
 * recorder has no open draft outside a live servicing pass -- so
 * recordModelCall() is a silent no-op there. Only the replay's own private
 * ModelPlatform (wired to TallyingDecisionWriter) contributes to
 * RunTally -- see services.php.
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
        $answer = $this->judge->assess(
            $settings->improvement->llm,
            DayPicture::of($all),
            $settings->agent->strategyPrompt ?? '',
            $settings->improvement->candidates,
        );

        if ($answer === null) {
            $this->writer->writeCompleted($runId, RunOutcome::empty($now, $settings->agent->llm->model), $context);

            return;
        }

        $sample = \array_slice($all, 0, $settings->improvement->sampleSize);
        $result = $this->harness->run($sample, $settings->agent, $answer->candidates, $context);

        $this->writer->writeCompleted(
            $runId,
            new RunOutcome(
                finishedAt: $now,
                tally: $result->tally,
                model: $settings->agent->llm->model,
                findings: $answer->findings,
                proposals: new RunProposals(
                    $result->control,
                    $result->diverged,
                    $settings->agent->strategyVersionId,
                    $result->proposals,
                ),
            ),
            $context,
        );
    }
}
