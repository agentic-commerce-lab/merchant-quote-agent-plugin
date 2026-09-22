<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

/**
 * The four counts an ImprovementRun row keeps about the replay work itself,
 * separate from what the replay found (see RunProposals): how many decisions
 * actually got replayed, how many were skipped, and what the replay's own
 * model calls cost -- never the judge's single call, which is not tallied
 * (see ImprovementRunner's docblock for why).
 */
final readonly class RunTally
{
    public function __construct(
        public int $sampled,
        public int $skipped,
        public int $promptTokens,
        public int $completionTokens,
    ) {}
}
