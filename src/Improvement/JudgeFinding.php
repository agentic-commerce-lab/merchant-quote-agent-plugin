<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

/**
 * One pattern the judge noticed across the window -- shaped to match
 * ImprovementRun::$findings exactly (`@var list<array{pattern: string, count:
 * int, evidence: list<string>}>|null`), so Task 11 can write the judge's
 * answer onto the run row without reshaping it.
 *
 * `evidence` is the judge's own short phrases about the PATTERN it saw in the
 * aggregate counts -- e.g. "grant band escalating near the cap" -- not a
 * quote from any one decision: the judge never saw a decision, only
 * DayPicture's prose, so it has no buyer text to quote in the first place.
 */
final readonly class JudgeFinding
{
    /** @param list<string> $evidence */
    public function __construct(
        public string $pattern,
        public int $count,
        public array $evidence,
    ) {}
}
