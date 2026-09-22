<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

use MerchantQuoteAgentPlugin\Strategy\StrategyVersion;
use MerchantQuoteAgentPlugin\Strategy\VersionStatus;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The candidate half of a completed run: one `merchant_quote_agent_strategy_version`
 * row per candidate, `status = 'proposed'`, appended to the lineage the
 * control prompt came from. Split out of ImprovementRunWriter so that class
 * keeps the run row's own lifecycle and this one keeps the strategy table's --
 * two tables, two writers, the same split QuoteDecisionRecord and
 * TerminalOutcomeWriter already follow.
 */
final readonly class StrategyProposalWriter
{
    public function __construct(
        private EntityRepository $versions,
    ) {}

    public function write(string $runId, RunOutcome $outcome, Context $context): void
    {
        $candidates = $outcome->proposals->candidates;

        if ($candidates === []) {
            return;
        }

        $strategyId = $this->strategyId($outcome->proposals->currentStrategyVersionId, $context);

        if ($strategyId === null) {
            return;
        }

        $rows = array_map(static fn(CandidateProposal $candidate): array => self::row(
            $runId,
            $strategyId,
            $outcome,
            $candidate,
        ), $candidates);

        $this->versions->create($rows, $context);
    }

    /**
     * The lineage a candidate is appended to. Read fresh rather than trusted
     * from the settings that were current at the start of the run: a merchant
     * could have edited the strategy while the night's replay was in flight.
     */
    private function strategyId(?string $currentVersionId, Context $context): ?string
    {
        if ($currentVersionId === null) {
            return null;
        }

        $version = $this->versions
            ->search(new Criteria([$currentVersionId]), $context)
            ->getEntities()
            ->first();

        return $version instanceof StrategyVersion ? $version->strategyId : null;
    }

    /** @return array<string, mixed> */
    private static function row(
        string $runId,
        string $strategyId,
        RunOutcome $outcome,
        CandidateProposal $candidate,
    ): array {
        return [
            'id' => Uuid::randomHex(),
            'strategyId' => $strategyId,
            'version' => null,
            'prompt' => $candidate->prompt,
            'status' => VersionStatus::Proposed->value,
            'runId' => $runId,
            'rationale' => $candidate->rationale,
            'evaluation' => [
                'control' => self::armScoreArray($outcome->proposals->control),
                'candidate' => self::armScoreArray($candidate->score),
                'sampleSize' => $outcome->tally->sampled,
                'skipped' => $outcome->tally->skipped,
                'diverged' => $outcome->proposals->diverged,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function armScoreArray(ArmScore $score): array
    {
        return [
            'sampled' => $score->sampled,
            'escalationRate' => $score->escalationRate,
            'meanGrantedPercent' => $score->meanGrantedPercent,
            'modelRefusals' => $score->modelRefusals,
            'failures' => $score->failures,
            'unmeasured' => $score->unmeasured,
        ];
    }
}
