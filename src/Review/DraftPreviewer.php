<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Audit\DecisionReviewStoreInterface;

/** A failed preview must not persist edited version rows or the edit marker. */
final readonly class DraftPreviewer
{
    public function __construct(
        private Connection $connection,
        private DraftReply $reply,
        private DecisionReviewStoreInterface $store,
    ) {}

    /** @return array<string, mixed> */
    public function preview(PendingDraft $pending, DraftEdits $edits): array
    {
        try {
            return $this->connection->transactional(function () use ($pending, $edits): array {
                $after = DraftEditor::apply($pending, $edits);
                $reply = $this->reply->compose($pending, $after);

                if (!$edits->isEmpty()) {
                    $this->store->markPreviewEdited($pending->record->id);
                    $pending->record->sentChanges = ['editedByMerchant' => true];
                }

                return DraftView::of($pending, $after, $reply);
            });
        } catch (InvalidReviewRequest $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw DraftPreviewFailed::after($error);
        }
    }
}
