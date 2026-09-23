<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

/**
 * Everything a harvested decision carries that DayPicture never reads --
 * inputs the replay harness and the administration need, never a model.
 *
 * `interpretedAsks` is the RAW stored interpretation -- exactly what
 * DecisionRecorder::recordAsk() wrote via InterpretationPayload::of() -- NOT
 * the shape AnonymizedDecision::asks() exports after stripping
 * `clarificationQuestions` and `humanReviewRequests`. That stripped shape can
 * never be rehydrated (InterpretationHydrator::hydrate() requires both of
 * those keys), so building this from it would make every decision count as
 * skipped. See HarvestedDecision's own docblock for the full argument and the
 * test that pins it.
 *
 * `interpretedAsks` and `extractPromptHash` exist on HarvestedDecision for the
 * replay harness, which needs them to re-run the same ask against a candidate
 * prompt, entirely in-process: it reaches NegotiationDecider's band check and
 * CappedAuthority's ceiling math, never a model call and never a written row.
 *
 * `strategyVersionId` and `strategyAssignmentSource` are the ladder's own
 * answer for this decision -- already selected columns on the decision row --
 * grouped here rather than in DecisionClassification because both travel
 * together with the replay's per-decision control prompt: `strategyVersionId`
 * is what ReplaySubjectResolver resolves BY ID (unfiltered by status, see
 * StrategyResolver::byVersionId()) to replay this decision's control arm
 * against the prompt that actually produced it, not the channel default (see
 * the per-strategy design brief). `strategyAssignmentSource` travels with it
 * purely for the administration -- nothing in this module reads it.
 *
 * None of the four fields here reaches DayPicture::describe() -- see that
 * class's own docblock for which five of HarvestedDecision's properties it
 * reads instead.
 */
final readonly class DecisionExtraction
{
    /** @param array<string, mixed>|null $interpretedAsks */
    public function __construct(
        public ?array $interpretedAsks,
        public ?string $extractPromptHash,
        public ?string $strategyVersionId,
        public ?string $strategyAssignmentSource,
    ) {}
}
