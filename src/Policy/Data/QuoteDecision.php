<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

/**
 * Mirrors the TS discriminated union (`kind: 'auto_reply' | 'escalate'`) as a
 * kind tag plus one payload object per variant, rather than a class hierarchy
 * — the two shapes don't share behavior, only a tag.
 */
final readonly class QuoteDecision
{
    private function __construct(
        public QuoteDecisionKind $kind,
        public ?QuoteAutoReplyDetails $autoReply = null,
        public ?QuoteEscalationDetails $escalation = null,
    ) {}

    public static function autoReply(QuoteAutoReplyDetails $details): self
    {
        return new self(kind: QuoteDecisionKind::AutoReply, autoReply: $details);
    }

    public static function escalate(QuoteEscalationDetails $details): self
    {
        return new self(kind: QuoteDecisionKind::Escalate, escalation: $details);
    }
}
