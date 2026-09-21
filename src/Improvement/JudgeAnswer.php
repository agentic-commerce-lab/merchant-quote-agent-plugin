<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

/**
 * The judge prompt's answer. This class IS the JSON schema the model answers
 * under -- ResponseFormatFactory generates it from these two properties, the
 * same way NegotiateResponse does for the negotiate call -- so there is no
 * hand-parsing on the way back in.
 */
final readonly class JudgeAnswer
{
    /**
     * @param list<JudgeFinding>   $findings
     * @param list<JudgeCandidate> $candidates
     */
    public function __construct(
        public array $findings,
        public array $candidates,
    ) {}
}
