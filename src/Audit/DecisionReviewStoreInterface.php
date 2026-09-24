<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

/**
 * Every write a Draft Mode review makes to a decision row after its pass,
 * behind one seam so the pipeline decorator and the review endpoints can be
 * tested without a database. Separate from DecisionRecordWriterInterface for
 * the reason TerminalOutcomeWriterInterface is.
 */
interface DecisionReviewStoreInterface
{
    public function find(string $decisionId): ?QuoteDecisionRecord;

    /**
     * Marks every pending draft of the quote superseded.
     *
     * @return list<string> the draft version ids those rows held, for the caller to delete
     */
    public function supersedePending(string $quoteId): array;

    /** @param array<string, mixed>|null $sentChanges null for a clarification, which changes no price */
    public function markSent(string $decisionId, string $sentReply, ?array $sentChanges): void;

    public function markRejected(string $decisionId): void;

    /** @param list<string> $reasons Review\FeedbackReason values */
    public function saveFeedback(string $decisionId, array $reasons, string $comment): void;
}
