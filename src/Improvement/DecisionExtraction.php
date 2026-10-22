<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

/**
 * What the extraction step produced for one harvested decision, and under
 * which prompt. `interpretedAsks` is the RAW stored interpretation -- exactly
 * what DecisionRecorder::recordAsk() wrote via InterpretationPayload::of() --
 * NOT the shape AnonymizedDecision::asks() exports after stripping
 * `clarificationQuestions` and `humanReviewRequests`. That stripped shape can
 * never be rehydrated (InterpretationHydrator::hydrate() requires both of
 * those keys), so building this from it would make every decision count as
 * skipped. See HarvestedDecision's own docblock for the full argument and the
 * test that pins it.
 *
 * Both fields exist on HarvestedDecision for the replay harness (Task 11),
 * which needs them to re-run the same ask against a candidate prompt, entirely
 * in-process: it reaches NegotiationDecider's band check and CappedAuthority's
 * ceiling math, never a model call and never a written row. Neither field
 * reaches DayPicture::describe() -- see that class's docblock.
 */
final readonly class DecisionExtraction
{
    /** @param array<string, mixed>|null $interpretedAsks */
    public function __construct(
        public ?array $interpretedAsks,
        public ?string $extractPromptHash,
    ) {}
}
