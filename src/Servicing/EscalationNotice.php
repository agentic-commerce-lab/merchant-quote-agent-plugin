<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;

/**
 * What the MERCHANT is told when a quote escalates — the half QuoteEscalator
 * deliberately has no channel for.
 *
 * The reason is in here and never in the buyer's comment. That split is the
 * whole point: QuoteEscalator's copy is customer-facing, so the internal
 * detail had nowhere to go but a log line, and a log line is not a
 * notification. Everything here is safe to show a merchant and none of it is
 * safe to show a buyer.
 */
final readonly class EscalationNotice
{
    public function __construct(
        public string $quoteId,
        public string $quoteNumber,
        public string $salesChannelId,
        public QuoteEscalationReason $reason,
    ) {}

    public static function of(QuoteSnapshot $snapshot, QuoteEscalationReason $reason): self
    {
        return new self(
            quoteId: $snapshot->identity->quoteId,
            quoteNumber: $snapshot->identity->quoteNumber,
            salesChannelId: $snapshot->identity->salesChannelId,
            reason: $reason,
        );
    }
}
