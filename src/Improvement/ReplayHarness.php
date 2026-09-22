<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use Shopware\Core\Framework\Context;

/**
 * The judge's candidates, replayed against a sample: control arm, then one
 * arm per candidate, all against the same resolved subjects so the delta is
 * attributable to the strategy prompt alone (see QuoteAgentSettings::withStrategyPrompt()).
 *
 * Tokens are read as a DELTA across this one call, not as $tally's running
 * total: $tally is a shared, long-lived service (one worker process replays
 * many channels, many nights), and this class must report only what THIS
 * sample's model calls cost.
 */
final readonly class ReplayHarness
{
    public function __construct(
        private ReplaySubjectResolver $subjects,
        private ReplayEvaluator $replay,
        private TallyingDecisionWriter $tally,
    ) {}

    /**
     * @param list<HarvestedDecision> $sample
     * @param list<JudgeCandidate>    $candidates
     */
    public function run(array $sample, QuoteAgentSettings $control, array $candidates, Context $context): ReplayResult
    {
        [$promptBefore, $completionBefore] = [$this->tally->promptTokens, $this->tally->completionTokens];

        $subjects = $this->resolve($sample, $context);
        $skipped = \count($sample) - \count($subjects);

        $controlArms = $this->controlArms($subjects, $control);
        $proposals = $this->candidateProposals($subjects, $control, $candidates);

        $controlScore = ReplayScore::of($controlArms);

        return new ReplayResult(
            tally: new RunTally(
                \count($subjects),
                $skipped,
                $this->tally->promptTokens - $promptBefore,
                $this->tally->completionTokens - $completionBefore,
            ),
            control: $controlScore,
            diverged: ControlDivergence::diverged($controlScore->escalationRate * 100, self::recordedRate($subjects)),
            proposals: $proposals,
        );
    }

    /**
     * @param list<ReplaySubject> $subjects
     *
     * @return list<ReplayArm>
     */
    private function controlArms(array $subjects, QuoteAgentSettings $control): array
    {
        return array_map(fn(ReplaySubject $subject): ReplayArm => $this->replay->replay(
            $control,
            $subject->snapshot,
            $subject->ask,
        ), $subjects);
    }

    /**
     * One arm per candidate, replayed against the SAME subjects the control
     * arm saw -- array_map's multi-array form zips $candidates against its own
     * arms by position, rather than an indexed lookup a static analyzer cannot
     * prove is always in bounds.
     *
     * @param list<ReplaySubject>  $subjects
     * @param list<JudgeCandidate> $candidates
     *
     * @return list<CandidateProposal>
     */
    private function candidateProposals(array $subjects, QuoteAgentSettings $control, array $candidates): array
    {
        $arms = array_map(fn(JudgeCandidate $candidate): array => array_map(
            fn(ReplaySubject $subject): ReplayArm => $this->replay->replay(
                $control->withStrategyPrompt($candidate->prompt),
                $subject->snapshot,
                $subject->ask,
            ),
            $subjects,
        ), $candidates);

        return array_map(
            static fn(JudgeCandidate $candidate, array $candidateArms): CandidateProposal => new CandidateProposal(
                $candidate->prompt,
                $candidate->reason,
                ReplayScore::of($candidateArms),
            ),
            $candidates,
            $arms,
        );
    }

    /**
     * @param list<HarvestedDecision> $sample
     *
     * @return list<ReplaySubject>
     */
    private function resolve(array $sample, Context $context): array
    {
        $subjects = [];

        foreach ($sample as $decision) {
            $subject = $this->subjects->resolve($decision, $context);

            if ($subject !== null) {
                $subjects[] = $subject;
            }
        }

        return $subjects;
    }

    /**
     * The night's own recorded escalation rate, over the same resolved sample
     * -- ControlDivergence's self-check.
     *
     * @param list<ReplaySubject> $subjects
     */
    private static function recordedRate(array $subjects): float
    {
        if ($subjects === []) {
            return 0.0;
        }

        $escalated = 0;

        foreach ($subjects as $subject) {
            if ($subject->decision->outcome === NegotiationOutcome::Escalated->value) {
                ++$escalated;
            }
        }

        return ($escalated / \count($subjects)) * 100;
    }
}
