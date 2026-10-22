<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

/**
 * Everything the replay found, as opposed to how much of it there was (see
 * RunTally): the control arm's own score, whether it diverged from what the
 * night actually recorded, which strategy version the control prompt came
 * from -- the lineage a candidate proposal is appended to -- and the
 * candidates themselves.
 */
final readonly class RunProposals
{
    /** @param list<CandidateProposal> $candidates */
    public function __construct(
        public ArmScore $control,
        public bool $diverged,
        public ?string $currentStrategyVersionId,
        public array $candidates,
    ) {}
}
