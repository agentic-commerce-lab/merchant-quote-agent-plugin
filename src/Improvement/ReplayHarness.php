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
 * The control arm is per DECISION, not per run: each subject carries its own
 * `controlPrompt` -- the prompt the version that actually produced it sent
 * (see ReplaySubject, ReplaySubjectResolver) -- so a decision the split arm
 * made is controlled against the split's own prompt, never against whichever
 * lineage's group this replay happens to be running for. `$control` supplies
 * everything ELSE every arm shares: policy, model access, and (for the
 * candidate arms, which all test the SAME replacement text against the SAME
 * subjects) the base a candidate's prompt is substituted onto.
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
     * The shared tally's running totals, read (never reset) so
     * ImprovementRunner can snapshot them before and after ImprovementJudge's
     * own call -- which bills the SAME writer, see ImprovementJudge's own
     * docblock -- and bill that call's tokens onto the run row too, without
     * this class needing to know ImprovementJudge exists.
     *
     * @return array{0: int, 1: int} prompt tokens, completion tokens
     */
    public function tokensSoFar(): array
    {
        return [$this->tally->promptTokens, $this->tally->completionTokens];
    }

    /**
     * @param list<HarvestedDecision> $sample
     * @param list<JudgeCandidate>    $candidates
     *
     * @return array{0: RunTally, 1: RunProposals} what ImprovementRunner needs
     *     to finish the run -- returned directly rather than through a
     *     wrapper: the only producer of a `RunProposals` was this method
     *     handing its own `ReplayResult` straight back into one two lines
     *     later in the caller, so the wrapper added a class without adding a
     *     seam.
     */
    public function run(array $sample, QuoteAgentSettings $control, array $candidates, Context $context): array
    {
        [$promptBefore, $completionBefore] = $this->tokensSoFar();

        $subjects = $this->resolve($sample, $context);
        $skipped = \count($sample) - \count($subjects);

        $controlArms = $this->controlArms($subjects, $control);
        $proposals = $this->candidateProposals($subjects, $control, $candidates);

        $controlScore = ReplayScore::of($controlArms);
        [$promptAfter, $completionAfter] = $this->tokensSoFar();

        return [
            new RunTally(
                \count($subjects),
                $skipped,
                $promptAfter - $promptBefore,
                $completionAfter - $completionBefore,
            ),
            new RunProposals(
                $controlScore,
                ControlDivergence::diverged($controlScore->escalationRate * 100, self::recordedRate($subjects)),
                $control->strategyVersionId,
                $proposals,
            ),
        ];
    }

    /**
     * @param list<ReplaySubject> $subjects
     *
     * @return list<ReplayArm>
     */
    private function controlArms(array $subjects, QuoteAgentSettings $control): array
    {
        return array_map(fn(ReplaySubject $subject): ReplayArm => $this->replay->replay(
            $control->withStrategyPrompt($subject->controlPrompt),
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
