<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

/**
 * What ImprovementRunWriter needs to finish a `running` row as `completed` --
 * grouped into RunTally and RunProposals so this constructor, and theirs,
 * each stay under the five-parameter gate.
 */
final readonly class RunOutcome
{
    /** @param list<JudgeFinding> $findings */
    public function __construct(
        public \DateTimeImmutable $finishedAt,
        public RunTally $tally,
        public ?string $model,
        public array $findings,
        public RunProposals $proposals,
    ) {}

    /**
     * The judge answered with nothing usable (ImprovementJudge::assess()
     * returned null): the run still completes, with no findings and no
     * proposal, rather than failing the night over an empty candidate list or
     * a model outage.
     */
    public static function empty(\DateTimeImmutable $finishedAt, ?string $model): self
    {
        return new self(
            finishedAt: $finishedAt,
            tally: new RunTally(0, 0, 0, 0),
            model: $model,
            findings: [],
            proposals: new RunProposals(ReplayScore::of([]), false, null, []),
        );
    }
}
