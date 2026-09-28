<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;

/** One pending draft, opened under the quote's lock: its row, the live quote, and the gateway onto its version (null for a draft that changes no price). */
final readonly class PendingDraft
{
    public function __construct(
        public QuoteDecisionRecord $record,
        public QuoteSnapshot $live,
        public ?QuoteGatewayInterface $draft,
        public bool $stale,
    ) {}

    public function draftSnapshot(): QuoteSnapshot
    {
        return $this->draft?->fetchSnapshot($this->record->quoteId) ?? $this->live;
    }

    public function wasEditedByMerchant(DraftEdits $edits, string $reply): bool
    {
        return !$edits->isEmpty() || $this->previewEdited() || $reply !== trim($this->record->replyToBuyer ?? '');
    }

    public function previewEdited(): bool
    {
        return ($this->record->sentChanges['editedByMerchant'] ?? false) === true;
    }
}
