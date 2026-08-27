<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data;

/**
 * The bridge's own full-fidelity read model — never a SwagCommercial
 * QuoteEntity, and deliberately NOT Policy\Data\QuoteSnapshot, which is
 * trimmed to what negotiation-core reads. Servicing (issue #4) adapts between
 * the two.
 */
final readonly class QuoteSnapshot
{
    public function __construct(
        public QuoteIdentity $identity,
        public QuoteRevision $revision,
        public QuoteTotals $totals,
        public QuoteLifecycle $lifecycle,
        public QuoteContent $content,
    ) {}
}
