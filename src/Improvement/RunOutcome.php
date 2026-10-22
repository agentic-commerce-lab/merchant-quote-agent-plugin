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
     * a model outage. The judge's own call still cost real tokens whenever it
     * actually reached the model (an outage records none -- see
     * ImprovementJudge), so those are billed here even though nothing else
     * about the call is kept.
     */
    public static function empty(
        \DateTimeImmutable $finishedAt,
        ?string $model,
        int $judgePromptTokens,
        int $judgeCompletionTokens,
    ): self {
        return new self(
            finishedAt: $finishedAt,
            tally: new RunTally(0, 0, $judgePromptTokens, $judgeCompletionTokens),
            model: $model,
            findings: [],
            proposals: new RunProposals(ReplayScore::of([]), false, null, []),
        );
    }
}
