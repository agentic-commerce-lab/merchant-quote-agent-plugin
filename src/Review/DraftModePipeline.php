<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Audit\DecisionReviewStoreInterface;
use MerchantQuoteAgentPlugin\Audit\ReviewStatus;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteDraftVersionsInterface;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Negotiation\ClarificationMarker;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use MerchantQuoteAgentPlugin\Servicing\Data\PassContext;
use MerchantQuoteAgentPlugin\Servicing\EscalationNotice;
use MerchantQuoteAgentPlugin\Servicing\EscalationNotifierInterface;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingPipelineInterface;

/**
 * Draft Mode around the negotiation pipeline, which stays exactly as it is.
 *
 * Every pass first retires the quote's pending drafts — whatever the buyer
 * did to trigger it made them stale — and deletes their versions (and a
 * clarification's marker), in or out of Draft Mode, so switching the mode off
 * cannot leave a draft to be sent against a conversation that has moved on.
 *
 * In Draft Mode the pass runs against a DraftingQuoteGateway. Afterwards a
 * pass that drafted something for the buyer tells the merchant; any other
 * pass discards the version it opened (a failed verification opens one and
 * then escalates).
 */
final readonly class DraftModePipeline implements QuoteServicingPipelineInterface
{
    public function __construct(
        private QuoteServicingPipelineInterface $inner,
        private QuoteDraftVersionsInterface $versions,
        private DecisionRecorder $recorder,
        private DecisionReviewStoreInterface $reviews,
        private EscalationNotifierInterface $notifier,
    ) {}

    /** @throws \Throwable rethrown from the inner pipeline once the version it opened is discarded */
    #[\Override]
    public function service(
        QuoteSnapshot $snapshot,
        QuoteGatewayInterface $gateway,
        QuoteAgentSettings $settings,
        PassContext $context,
    ): NegotiationOutcome {
        $quoteId = $snapshot->identity->quoteId;
        $snapshot = $this->retirePending($snapshot, $gateway);

        if (!$settings->draftMode) {
            return $this->inner->service($snapshot, $gateway, $settings, $context);
        }

        $drafting = new DraftingQuoteGateway($gateway, $this->versions, $this->recorder, $snapshot);

        try {
            $outcome = $this->inner->service($snapshot, $drafting, $settings, $context);
        } catch (\Throwable $e) {
            $this->discard($quoteId, $drafting->versionId());

            throw $e;
        }

        if (!ReviewStatus::awaitsReview($outcome)) {
            $this->discard($quoteId, $drafting->versionId());

            return $outcome;
        }

        try {
            $this->notifier->notify(EscalationNotice::of($snapshot, QuoteEscalationReason::DraftReady));
        } catch (\Throwable) {
            // @mago-expect lint:no-empty-catch-clause
            // Deliberately empty, as in QuoteEscalator: the notifier owns its
            // own logging, and the draft is on the list page either way.
        }

        return $outcome;
    }

    /**
     * Supersedes the quote's pending drafts and undoes what each left on the
     * quote: its version, and — for a clarification — the marker its pass set
     * live, the same write DraftRejecter makes. Left set, the buyer's next
     * still-ambiguous ask would escalate as "already asked" for a question
     * they never saw.
     *
     * @return QuoteSnapshot the snapshot the pass decides on: re-read once a
     *                       marker was released, because the one handed in
     *                       still carries it and ClarificationRound reads
     *                       that one
     */
    private function retirePending(QuoteSnapshot $snapshot, QuoteGatewayInterface $gateway): QuoteSnapshot
    {
        $quoteId = $snapshot->identity->quoteId;
        $clarified = false;

        foreach ($this->reviews->supersedePending($quoteId) as $superseded) {
            $this->discard($quoteId, $superseded['versionId']);
            $clarified = $clarified || $superseded['clarified'];
        }

        if (!$clarified) {
            return $snapshot;
        }

        $gateway->updateQuote($quoteId, new QuoteUpdate(customFields: [ClarificationMarker::MARKER_KEY => null]));

        return $gateway->fetchSnapshot($quoteId);
    }

    private function discard(string $quoteId, ?string $versionId): void
    {
        if ($versionId === null) {
            return;
        }

        try {
            $this->versions->delete($quoteId, $versionId);
        } catch (\Throwable) {
            // @mago-expect lint:no-empty-catch-clause
            // QuoteDraftVersions logged the failure with its exception. A
            // version nobody references is invisible to buyer and merchant;
            // failing this pass would redeliver and draft the quote twice.
        }
    }
}
