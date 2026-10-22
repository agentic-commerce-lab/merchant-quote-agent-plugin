<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Negotiation\InterpretedAsk;

/**
 * One harvested decision, resolved into what ReplayEvaluator actually needs
 * to replay it -- a live quote snapshot and the same structured ask the live
 * pass saw. `decision` travels alongside for ReplayHarness's own self-check:
 * what the night actually recorded for this same decision.
 */
final readonly class ReplaySubject
{
    public function __construct(
        public QuoteSnapshot $snapshot,
        public ?InterpretedAsk $ask,
        public HarvestedDecision $decision,
    ) {}
}
