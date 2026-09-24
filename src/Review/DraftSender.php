<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

use MerchantQuoteAgentPlugin\Audit\DecisionReviewStoreInterface;
use MerchantQuoteAgentPlugin\Bridge\ContextBoundGateways;
use MerchantQuoteAgentPlugin\Bridge\Data\DiscountType;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Bridge\QuoteDraftVersionsInterface;
use MerchantQuoteAgentPlugin\Negotiation\ReplyComposer;
use Shopware\Core\Framework\Context;

final readonly class DraftSender
{
    public function __construct(
        private QuoteDraftVersionsInterface $versions,
        private ContextBoundGateways $gateways,
        private DecisionReviewStoreInterface $store,
    ) {}

    /** @throws DraftNotReviewable|InvalidReviewRequest */
    public function send(PendingDraft $pending, string $reply, DraftEdits $edits, Context $merchant): void
    {
        if ($pending->stale) {
            throw DraftNotReviewable::stale();
        }

        $reply = trim($reply);

        if ($reply === '') {
            throw InvalidReviewRequest::because('The reply to the buyer is empty.');
        }

        $this->publish($pending, $reply, $edits, MerchantSendContext::from($merchant));
    }

    private function publish(PendingDraft $pending, string $reply, DraftEdits $edits, Context $merchant): void
    {
        $gateway = $this->gateways->forContext($merchant) ?? throw DraftNotReviewable::unavailable();
        $quoteId = $pending->record->quoteId;
        $versionId = $pending->record->draftVersionId;
        $sentChanges = null;

        if ($versionId !== null) {
            $sentChanges = self::sentChanges(DraftEditor::apply($pending, $edits), !$edits->isEmpty());
            $this->versions->merge($versionId);

            if ($pending->live->lifecycle->stateTechnicalName === 'open') {
                $gateway->transition($quoteId, QuoteTransition::Process);
            }
        }

        $gateway->addComment($quoteId, $reply);

        if ($versionId !== null) {
            $gateway->transition(
                $quoteId,
                ReplyComposer::transitionFor($gateway->fetchSnapshot($quoteId)->lifecycle->stateTechnicalName),
            );
        }

        $this->store->markSent($pending->record->id, $reply, $sentChanges);
    }

    /** @return array<string, mixed> */
    private static function sentChanges(QuoteSnapshot $after, bool $edited): array
    {
        $discount = $after->totals->discount;

        return [
            'discountPercent' =>
                $discount !== null && $discount->type === DiscountType::Percentage ? $discount->value : null,
            'totalNet' => $after->totals->totalNet,
            'totalGross' => $after->totals->totalGross,
            'expiresAt' => $after->lifecycle->expiresAt?->format(\DateTimeInterface::ATOM),
            'editedByMerchant' => $edited,
        ];
    }
}
