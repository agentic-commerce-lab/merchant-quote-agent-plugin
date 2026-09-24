<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

use MerchantQuoteAgentPlugin\Audit\DecisionReviewStoreInterface;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteDraftVersionsInterface;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Negotiation\ClarificationMarker;

final readonly class DraftRejecter
{
    public function __construct(
        private QuoteDraftVersionsInterface $versions,
        private ?QuoteGatewayInterface $live,
        private DecisionReviewStoreInterface $store,
    ) {}

    /** @throws DraftNotReviewable */
    public function reject(PendingDraft $pending): void
    {
        $versionId = $pending->record->draftVersionId;

        if ($versionId !== null) {
            $this->versions->delete($pending->record->quoteId, $versionId);
        }

        if ($pending->record->outcome === 'clarified') {
            ($this->live ?? throw DraftNotReviewable::unavailable())->updateQuote(
                $pending->record->quoteId,
                new QuoteUpdate(customFields: [ClarificationMarker::MARKER_KEY => null]),
            );
        }

        $this->store->markRejected($pending->record->id);
    }
}
