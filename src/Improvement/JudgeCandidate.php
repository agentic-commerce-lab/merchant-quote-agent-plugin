<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

/**
 * One candidate replacement for the merchant's strategy prompt.
 *
 * `prompt` replaces the strategy SECTION only -- PromptComposer::negotiate()
 * still owns the base instructions and the delimiting heading, exactly as it
 * does for the merchant's own strategy text today. A blank prompt is not a
 * legal candidate; ImprovementJudge drops it rather than replaying a no-op.
 */
final readonly class JudgeCandidate
{
    public function __construct(
        public string $prompt,
        public string $reason,
    ) {}
}
