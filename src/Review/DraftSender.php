<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Bridge\ContextBoundGateways;
use MerchantQuoteAgentPlugin\Bridge\QuoteDraftVersionsInterface;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use Shopware\Core\Framework\Context;

final readonly class DraftSender
{
    public function __construct(
        private QuoteDraftVersionsInterface $versions,
        private ContextBoundGateways $gateways,
        private DraftSendCompletion $completion,
        private Connection $connection,
    ) {}

    /** @throws DraftNotReviewable|InvalidReviewRequest */
    public function send(PendingDraft $pending, string $reply, DraftEdits $edits, Context $merchant): void
    {
        if ($pending->stale) {
            throw DraftNotReviewable::stale();
        }

        $reply = trim($reply);

        if ($reply === '') {
            throw InvalidReviewRequest::because(InvalidReviewReason::EmptyReply, 'The reply to the buyer is empty.');
        }

        $this->publish($pending, $reply, $edits, MerchantSendContext::from($merchant));
    }

    private function publish(PendingDraft $pending, string $reply, DraftEdits $edits, Context $merchant): void
    {
        $gateway = $this->gateways->forContext($merchant) ?? throw DraftNotReviewable::unavailable();
        $quoteId = $pending->record->quoteId;
        $versionId = $pending->record->draftVersionId;
        $edited = $pending->wasEditedByMerchant($edits, $reply);

        if ($versionId !== null) {
            $this->mergeReviewedDraft($pending, $edits, $gateway, $versionId);
        }

        $this->completion->complete($pending, $gateway, $reply, $edited);
    }

    private function mergeReviewedDraft(
        PendingDraft $pending,
        DraftEdits $edits,
        QuoteGatewayInterface $gateway,
        string $versionId,
    ): void {
        try {
            $this->connection->transactional(
                /** @throws \Doctrine\DBAL\Exception */ function () use ($pending, $edits, $gateway, $versionId): void {
                    $quoteId = $pending->record->quoteId;
                    // One DBAL connection, so the lock holds until this commits.
                    $this->versions->lockLive($quoteId);
                    $live = $gateway->fetchSnapshot($quoteId);

                    if (ReviewFingerprint::current($live) !== $pending->record->reviewFingerprint) {
                        throw DraftNotReviewable::stale();
                    }

                    $current = new PendingDraft($pending->record, $live, $pending->draft, false);
                    $after = DraftEditor::apply($current, $edits);
                    DraftPriceGuard::reduction($current, $after);
                    $this->versions->merge($versionId);
                },
            );
        } catch (InvalidReviewRequest|DraftNotReviewable $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw DraftSendFailed::after($error);
        }
    }
}
