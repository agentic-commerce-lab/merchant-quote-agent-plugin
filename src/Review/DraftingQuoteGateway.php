<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineItemChange;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteVersion;
use MerchantQuoteAgentPlugin\Bridge\QuoteDraftVersionsInterface;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Bridge\QuoteRevisionMismatch;
use MerchantQuoteAgentPlugin\Servicing\ServicingFingerprint;

/**
 * The gateway one Draft Mode pass runs against, so the pipeline itself does
 * not change: the same interpretation, the same bands, the same offer
 * applier and verifier, the same reply composer — only where their writes
 * land differs.
 *
 * Routed by what a write IS, not by who calls it:
 *
 * - A price, discount, validity or line change goes into a DAL version of the
 *   quote, opened on the first such write. The verifier then reads the
 *   version back exactly as it reads the live quote today.
 * - A write of only customFields (the markers, the baseline stamp, the
 *   attempt counter) or of only the buyer's requested prices (AskMirror)
 *   stays live: that is bookkeeping and the buyer's own data, not an answer.
 * - A comment is recorded as the draft reply and posted nowhere. A transition
 *   does nothing: `process` and `sent` are what put the quote in front of the
 *   buyer, and the merchant's Send performs them.
 *
 * Per pass: DraftModePipeline builds one for each Draft Mode pass and
 * discards it afterwards.
 *
 * @mago-expect lint:too-many-methods
 * Seven interface methods, versionId() for the decorator, and the two helpers
 * every draft write shares: draftFor() opens the version once, recordDraft()
 * takes the review fingerprint. Moving either out would hand a second class
 * this pass's version state.
 *
 * @mago-expect lint:cyclomatic-complexity
 * The rule aggregates per class (threshold 10) and every branch is one
 * routing decision this class exists to make: draft or live for each kind of
 * write, reads following the draft once it exists, and the live-revision
 * precondition on the first draft write.
 */
final class DraftingQuoteGateway implements QuoteGatewayInterface
{
    private ?string $versionId = null;

    private ?QuoteGatewayInterface $draft = null;

    public function __construct(
        private readonly QuoteGatewayInterface $live,
        private readonly QuoteDraftVersionsInterface $versions,
        private readonly DecisionRecorder $recorder,
        private readonly QuoteSnapshot $serviced,
    ) {}

    /** The version this pass opened, or null when it wrote no price. */
    public function versionId(): ?string
    {
        return $this->versionId;
    }

    #[\Override]
    public function fetchSnapshot(string $quoteId, QuoteVersion $version = QuoteVersion::Live): QuoteSnapshot
    {
        if ($this->draft !== null && $version === QuoteVersion::Live) {
            return $this->draft->fetchSnapshot($quoteId);
        }

        return $this->live->fetchSnapshot($quoteId, $version);
    }

    /** @param list<QuoteLineItemChange> $changes */
    #[\Override]
    public function updateLineItems(string $quoteId, array $changes, ?QuoteRevision $expected = null): void
    {
        if (self::onlyAsks($changes)) {
            $this->live->updateLineItems($quoteId, $changes, $expected);

            return;
        }

        $this->draftFor($quoteId, $expected)->updateLineItems($quoteId, $changes);
    }

    #[\Override]
    public function updateQuote(string $quoteId, QuoteUpdate $update, ?QuoteRevision $expected = null): void
    {
        if ($update->discount === null && $update->expiresAt === null) {
            $this->live->updateQuote($quoteId, $update, $expected);

            return;
        }

        $this->draftFor($quoteId, $expected)->updateQuote($quoteId, $update);
    }

    #[\Override]
    public function addProduct(string $quoteId, string $productId, int $quantity): void
    {
        $this->draftFor($quoteId, null)->addProduct($quoteId, $productId, $quantity);
    }

    #[\Override]
    public function recalculate(string $quoteId): void
    {
        ($this->draft ?? $this->live)->recalculate($quoteId);
    }

    #[\Override]
    public function addComment(string $quoteId, string $comment): void
    {
        $this->recordDraft($quoteId);
        $this->recorder->recordReply($comment, null);
    }

    #[\Override]
    public function transition(string $quoteId, QuoteTransition $action): void
    {
        // Deliberately nothing: see the class docblock.
    }

    private function draftFor(string $quoteId, ?QuoteRevision $expected): QuoteGatewayInterface
    {
        if ($this->draft !== null) {
            return $this->draft;
        }

        // The precondition guards the LIVE quote — a buyer edit between the
        // pass's read and its first write must lose, loudly, exactly as it
        // does outside Draft Mode. The version is fresh, so it has nothing
        // to compare against.
        if ($expected !== null && !$this->live->fetchSnapshot($quoteId)->revision->matches($expected)) {
            throw QuoteRevisionMismatch::forId($quoteId);
        }

        $this->versionId = $this->versions->create($quoteId);
        $draft = $this->versions->gateway($this->versionId);
        $this->draft = $draft;
        $this->recordDraft($quoteId);

        return $draft;
    }

    private function recordDraft(string $quoteId): void
    {
        $this->recorder->recordDraft(
            $this->versionId,
            ServicingFingerprint::review($this->serviced, $this->live->fetchSnapshot($quoteId)),
        );
    }

    /** @param list<QuoteLineItemChange> $changes */
    private static function onlyAsks(array $changes): bool
    {
        foreach ($changes as $change) {
            if ($change->touchesPrice() || $change->quantity !== null || $change->isRemoval()) {
                return false;
            }
        }

        return true;
    }
}
