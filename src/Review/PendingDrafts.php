<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

use MerchantQuoteAgentPlugin\Audit\DecisionReviewStoreInterface;
use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use MerchantQuoteAgentPlugin\Audit\ReviewStatus;
use MerchantQuoteAgentPlugin\Bridge\QuoteDraftVersionsInterface;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingLock;

/**
 * Opens a pending draft for one review action, under the SAME per-quote lock
 * a servicing pass holds — so a Send can never interleave with a pass that is
 * about to supersede the draft it sends. Non-blocking: a busy quote is a 409
 * the card retries, not a request that hangs.
 *
 * The row is read again once the lock is held; the read before it only
 * supplies the quote id to lock on.
 *
 * A row can name a version whose rows are already gone (a Send that failed
 * after its merge), and a versioned read of it falls back to the live quote
 * rather than failing. with() refuses such a draft; withAnyDraft() hands it
 * over without a gateway, for the one action that needs no prices: Reject.
 */
final readonly class PendingDrafts
{
    public function __construct(
        private DecisionReviewStoreInterface $store,
        private QuoteDraftVersionsInterface $versions,
        private ?QuoteGatewayInterface $live,
        private QuoteServicingLock $locks,
    ) {}

    /**
     * @template T
     *
     * @param \Closure(PendingDraft): T $work
     *
     * @return T
     *
     * @throws DecisionNotFound|DraftNotReviewable
     */
    public function with(string $decisionId, \Closure $work): mixed
    {
        return $this->open($decisionId, $work, requireDraft: true);
    }

    /**
     * with(), except that a draft whose version is gone is handed over with a
     * null `draft` instead of refused.
     *
     * @template T
     *
     * @param \Closure(PendingDraft): T $work
     *
     * @return T
     *
     * @throws DecisionNotFound|DraftNotReviewable
     */
    public function withAnyDraft(string $decisionId, \Closure $work): mixed
    {
        return $this->open($decisionId, $work, requireDraft: false);
    }

    /**
     * @template T
     *
     * @param \Closure(PendingDraft): T $work
     *
     * @return T
     *
     * @throws DecisionNotFound|DraftNotReviewable
     */
    private function open(string $decisionId, \Closure $work, bool $requireDraft): mixed
    {
        $quoteId = $this->find($decisionId)->quoteId;
        $gateway = $this->live ?? throw DraftNotReviewable::unavailable();
        $lock = $this->locks->for($quoteId);

        if (!$lock->acquire()) {
            throw DraftNotReviewable::busy();
        }

        try {
            $record = $this->find($decisionId);

            if ($record->reviewStatus !== ReviewStatus::Pending->value) {
                throw DraftNotReviewable::notPending();
            }

            $live = $gateway->fetchSnapshot($quoteId);

            if (PublishingReply::visible($record, $live)) {
                throw DraftNotReviewable::published();
            }

            $draft = $this->draft($quoteId, $record->draftVersionId, $requireDraft);

            return $work(
                new PendingDraft(
                    $record,
                    $live,
                    $draft,
                    ReviewFingerprint::current($live) !== $record->reviewFingerprint,
                ),
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * The gateway onto the draft's version: null for a clarification, which
     * drafted no prices, and for a version that is gone unless one is required.
     *
     * @throws DraftNotReviewable
     */
    private function draft(string $quoteId, ?string $versionId, bool $requireDraft): ?QuoteGatewayInterface
    {
        if ($versionId === null) {
            return null;
        }

        if ($this->versions->exists($quoteId, $versionId)) {
            return $this->versions->gateway($versionId);
        }

        return $requireDraft ? throw DraftNotReviewable::gone() : null;
    }

    /** @throws DecisionNotFound */
    private function find(string $decisionId): QuoteDecisionRecord
    {
        return $this->store->find($decisionId) ?? throw DecisionNotFound::forId($decisionId);
    }
}
