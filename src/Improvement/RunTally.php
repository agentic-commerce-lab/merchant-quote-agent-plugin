<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

/**
 * The four counts an ImprovementRun row keeps about the replay work itself,
 * separate from what the replay found (see RunProposals): how many decisions
 * actually got replayed, how many were skipped, and what the replay's own
 * model calls cost.
 *
 * ReplayHarness::run() returns one of these carrying only the replay's own
 * token delta (see that method's own docblock); ImprovementRunner then builds
 * a SECOND instance, adding the judge's own call on top, before it reaches
 * the run row -- see ImprovementRunner::evaluateAndWrite() for why the two
 * deltas can be added rather than double-counted.
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
