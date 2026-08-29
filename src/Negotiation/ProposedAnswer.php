<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;

/**
 * Either an authorized offer to apply, or a reason to escalate. Never both,
 * never neither.
 */
final readonly class ProposedAnswer
{
    private function __construct(
        public ?ProposedOffer $offer,
        public ?QuoteEscalationReason $escalation,
        public string $escalationDetail,
        public string $modelMessage,
        public ?string $promptHash,
    ) {}

    public static function offer(ProposedOffer $offer, string $modelMessage, ?string $promptHash): self
    {
        return new self($offer, null, '', $modelMessage, $promptHash);
    }

    public static function escalate(QuoteEscalationReason $reason, string $detail, ?string $promptHash): self
    {
        return new self(null, $reason, $detail, '', $promptHash);
    }
}
