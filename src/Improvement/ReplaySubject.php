<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Negotiation\InterpretedAsk;

/**
 * One harvested decision, resolved into what ReplayEvaluator actually needs
 * to replay it -- a live quote snapshot, the same structured ask the live
 * pass saw, and the prompt that version actually sent. `decision` travels
 * alongside for ReplayHarness's own self-check: what the night actually
 * recorded for this same decision.
 *
 * `controlPrompt` is the strategy prompt of `decision->strategyVersionId`,
 * resolved by ReplaySubjectResolver -- NOT the channel's current prompt. This
 * is what makes the control arm honest per decision: a decision the split
 * arm produced is controlled against the split's own prompt, never the
 * channel default (see the per-strategy design brief).
 */
final readonly class ReplaySubject
{
    public function __construct(
        public QuoteSnapshot $snapshot,
        public ?InterpretedAsk $ask,
        public HarvestedDecision $decision,
        public string $controlPrompt,
    ) {}
}
