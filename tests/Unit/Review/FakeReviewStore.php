<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use MerchantQuoteAgentPlugin\Audit\DecisionReviewStoreInterface;
use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;

final class FakeReviewStore implements DecisionReviewStoreInterface
{
    /** @var list<string> version ids supersedePending() hands back */
    public array $pendingVersions = [];

    /** @var list<array{string, string, ?array<string, mixed>}> */
    public array $sent = [];

    /** @var list<string> */
    public array $rejected = [];

    /** @var list<string> */
    public array $previewEdited = [];

    /** @var list<array{string, int, bool}> */
    public array $publishing = [];

    public ?\Throwable $markSentThrows = null;

    /** @var list<array{string, list<string>, string}> */
    public array $feedback = [];

    public ?QuoteDecisionRecord $record = null;

    #[\Override]
    public function find(string $decisionId): ?QuoteDecisionRecord
    {
        return $this->record;
    }

    #[\Override]
    public function supersedePending(string $quoteId): array
    {
        $versions = $this->pendingVersions;
        $this->pendingVersions = [];

        return $versions;
    }

    #[\Override]
    public function markPreviewEdited(string $decisionId): void
    {
        $this->previewEdited[] = $decisionId;
    }

    public function markPublishing(string $decisionId, int $merchantCommentCount, bool $editedByMerchant): void
    {
        $this->publishing[] = [$decisionId, $merchantCommentCount, $editedByMerchant];
    }

    #[\Override]
    public function markSent(string $decisionId, string $sentReply, ?array $sentChanges): void
    {
        if ($this->markSentThrows !== null) {
            throw $this->markSentThrows;
        }

        $this->sent[] = [$decisionId, $sentReply, $sentChanges];
    }

    #[\Override]
    public function markRejected(string $decisionId): void
    {
        $this->rejected[] = $decisionId;
    }

    #[\Override]
    public function saveFeedback(string $decisionId, array $reasons, string $comment): void
    {
        $this->feedback[] = [$decisionId, $reasons, $comment];
    }
}
