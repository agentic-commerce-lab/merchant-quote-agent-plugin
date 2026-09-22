<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

/** What ReplayHarness::run() hands back to ImprovementRunner for one channel's sample. */
final readonly class ReplayResult
{
    /** @param list<CandidateProposal> $proposals */
    public function __construct(
        public RunTally $tally,
        public ArmScore $control,
        public bool $diverged,
        public array $proposals,
    ) {}
}
