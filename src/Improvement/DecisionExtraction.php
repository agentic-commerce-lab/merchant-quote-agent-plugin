<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

/**
 * What the extraction step produced for one harvested decision, and under
 * which prompt. `interpretedAsks` is the STRUCTURED ask (price, quantity,
 * delivery, payment, bundle -- the same shape AnonymizedDecision::asks()
 * keeps after it strips the two free-text lists off it) -- never the
 * sentences the buyer wrote or the model's own clarification questions.
 *
 * Both fields exist on HarvestedDecision for the replay harness (Task 11),
 * which needs them to re-run the same ask against a candidate prompt. Neither
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
