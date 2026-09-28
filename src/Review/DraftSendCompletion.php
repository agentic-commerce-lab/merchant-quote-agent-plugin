<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

use MerchantQuoteAgentPlugin\Audit\DecisionReviewStoreInterface;
use MerchantQuoteAgentPlugin\Bridge\Data\DiscountType;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Negotiation\ReplyComposer;
use Psr\Log\LoggerInterface;

/** Finishes the buyer-visible effects after prices have been merged. */
final readonly class DraftSendCompletion
{
    public function __construct(
        private DecisionReviewStoreInterface $store,
        private LoggerInterface $logger,
    ) {}

    public function complete(
        PendingDraft $pending,
        QuoteGatewayInterface $gateway,
        string $reply,
        bool $editedByMerchant,
    ): void {
        try {
            $before = $gateway->fetchSnapshot($pending->record->quoteId);
            $this->store->markPublishing(
                $pending->record->id,
                PublishingReply::merchantCommentCount($before),
                $editedByMerchant,
            );
            $this->publish($pending, $gateway, $reply);
            $sentChanges = $pending->record->draftVersionId === null
                ? null
                : self::sentChanges($gateway->fetchSnapshot($pending->record->quoteId), $editedByMerchant);
            $this->store->markSent($pending->record->id, $reply, $sentChanges);
        } catch (\Throwable $error) {
            $this->logger->error('Draft send failed; review remains pending. Inspect the quote before retrying.', [
                'decisionId' => $pending->record->id,
                'quoteId' => $pending->record->quoteId,
                'exception' => $error,
            ]);

            throw DraftSendFailed::after($error);
        }
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

    /**
     * The claim follows the version: only a draft that changed prices claims
     * the quote, as OfferApplier does. The closing transition follows the
     * outcome instead, as it does outside Draft Mode: a clarification only
     * asks its question, while an acknowledgement has no version and must
     * still move the quote back to `replied`, where the buyer can accept.
     */
    private function publish(PendingDraft $pending, QuoteGatewayInterface $gateway, string $reply): void
    {
        $quoteId = $pending->record->quoteId;

        if ($pending->record->draftVersionId !== null && $pending->live->lifecycle->stateTechnicalName === 'open') {
            $gateway->transition($quoteId, QuoteTransition::Process);
        }

        $gateway->addComment($quoteId, $reply);

        if ($pending->record->outcome !== NegotiationOutcome::Clarified->value) {
            $gateway->transition(
                $quoteId,
                ReplyComposer::transitionFor($gateway->fetchSnapshot($quoteId)->lifecycle->stateTechnicalName),
            );
        }
    }
}
