<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

use MerchantQuoteAgentPlugin\Audit\DecisionReviewStoreInterface;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Negotiation\ReplyComposer;
use Psr\Log\LoggerInterface;

/** Finishes the buyer-visible effects after prices have been merged. */
final readonly class DraftSendCompletion
{
    public function __construct(
        private DecisionReviewStoreInterface $store,
        private LoggerInterface $logger,
    ) {}

    /** @param array<string, mixed>|null $sentChanges */
    public function complete(
        PendingDraft $pending,
        QuoteGatewayInterface $gateway,
        string $reply,
        ?array $sentChanges,
    ): void {
        try {
            $this->publish($pending, $gateway, $reply);
            $this->store->markSent($pending->record->id, $reply, $sentChanges);
        } catch (\Throwable $error) {
            if ($pending->record->draftVersionId !== null) {
                $this->logger->error('Draft send failed after merging its prices; review remains pending.', [
                    'decisionId' => $pending->record->id,
                    'quoteId' => $pending->record->quoteId,
                    'exception' => $error,
                ]);
            }

            throw DraftSendFailed::after($error);
        }
    }

    private function publish(PendingDraft $pending, QuoteGatewayInterface $gateway, string $reply): void
    {
        $quoteId = $pending->record->quoteId;
        $versionId = $pending->record->draftVersionId;

        if ($versionId !== null && $pending->live->lifecycle->stateTechnicalName === 'open') {
            $gateway->transition($quoteId, QuoteTransition::Process);
        }

        $gateway->addComment($quoteId, $reply);

        if ($versionId !== null) {
            $gateway->transition(
                $quoteId,
                ReplyComposer::transitionFor($gateway->fetchSnapshot($quoteId)->lifecycle->stateTechnicalName),
            );
        }
    }
}
