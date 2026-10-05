<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

/**
 * One candidate strategy prompt, replayed, with its own arm's score attached
 * -- what ImprovementRunWriter turns into one `merchant_quote_agent_strategy_version`
 * row with `status = 'proposed'`.
 */
final readonly class CandidateProposal
{
    public function __construct(
        public string $prompt,
        public string $rationale,
        public ArmScore $score,
    ) {}
}
