<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Policy\Data\Band;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationDecision;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use MerchantQuoteAgentPlugin\Servicing\QuoteEscalator;
use MerchantQuoteAgentPlugin\Servicing\ServicingFingerprint;
use Psr\Log\LoggerInterface;

/**
 * Everything after the gate: propose → apply/verify → reply.
 *
 * Split out of NegotiationPipeline because the gate and the round together
 * exceed the class-scoped complexity and constructor-size budgets — not
 * because the stages are optional. Reaching this class at all means the
 * deterministic band has already said the ask is inside the merchant's
 * authority, which is what makes the two model calls below worth paying for.
 */
final readonly class OfferRound
{
    public function __construct(
        private OfferProposer $proposer,
        private OfferApplier $applier,
        private ReplyComposer $reply,
        private QuoteEscalator $escalator,
        private LoggerInterface $logger,
    ) {}

    /** @throws ModelUnavailable */
    public function play(
        QuoteGatewayInterface $gateway,
        QuoteSnapshot $snapshot,
        QuoteAgentSettings $settings,
        NegotiationDecision $decision,
        ?string $extractHash,
    ): NegotiationPass {
        $conversation = SnapshotAdapter::conversation($snapshot);
        $baseline = QuoteBaseline::read($snapshot);
        $answer = $this->proposer->propose(
            $settings,
            SnapshotAdapter::toPolicy($snapshot),
            $decision->price,
            $conversation,
            $baseline,
        );

        if ($answer->offer === null) {
            // The detail stays here, in the log: QuoteEscalator writes to the
            // conversation the BUYER reads, so nothing internal may travel.
            $this->logger->info('No offer was proposed for this quote; a human takes it.', [
                'quoteId' => $snapshot->identity->quoteId,
                'detail' => $answer->escalationDetail,
            ]);

            return $this->escalated($gateway, $snapshot, $answer->escalation, $extractHash, $answer->promptHash);
        }

        if ($answer->offer->price->linePricesNet !== null && $baseline === null && self::servicedBefore($snapshot)) {
            // #49 anchors per-line offers to a stored baseline, so round two
            // is no longer a human's — except on quotes serviced before that
            // baseline existed. Those have no anchor, so a per-line offer on
            // them would still be measured against already-reduced prices.
            // Retires itself as those quotes close.
            $this->logger->info('A per-line ask on a quote with no stored baseline; a human takes it.', [
                'quoteId' => $snapshot->identity->quoteId,
            ]);

            return $this->escalated($gateway, $snapshot, null, $extractHash, $answer->promptHash);
        }

        $applied = $this->applier->apply($gateway, $snapshot, $settings, $answer->offer);

        if (!$applied->verified) {
            $this->logger->error('The database disagreed with the offer we applied; escalating.', [
                'quoteId' => $snapshot->identity->quoteId,
                'violations' => $applied->violations,
            ]);

            // Deliberately no rollback: see OfferApplier. We escalate against
            // the post-write snapshot, which is what the buyer now has.
            $reason = QuoteEscalationReason::VerificationFailed;

            return $this->escalated($gateway, $applied->after, $reason, $extractHash, $answer->promptHash);
        }

        // What the buyer is told is what the DATABASE says the quote came down
        // by — the offer's own `discountPercent` is null for a per-line
        // concession, and announcing that as a percentage tells the buyer zero.
        $replyHash = $this->reply->reply(
            $gateway,
            $applied->after,
            $settings,
            ReplyTemplate::reduction($snapshot->totals->totalNet, $applied->after->totals->totalNet),
            $conversation,
        );

        return new NegotiationPass(
            $decision->overall === Band::Counter ? NegotiationOutcome::Countered : NegotiationOutcome::Offered,
            $extractHash,
            $answer->promptHash,
            $replyHash,
        );
    }

    /**
     * A pass that posted its reply and then died leaves the quote in
     * `in_review`: the retry reads the agent's own comment as the newest one,
     * so AskInterpreter returns null and the pipeline stops before this class
     * — and the `sent` transition never happens. The buyer holds a correct,
     * verified offer against a quote that still says it is being reviewed.
     *
     * The state check alone is enough. A crash BEFORE the comment also leaves
     * `in_review`, but then no agent comment exists, the buyer's ask is still
     * the newest, and the retry replays the whole round instead of arriving
     * here.
     */
    public function finishStrandedReply(QuoteGatewayInterface $gateway, QuoteSnapshot $snapshot): void
    {
        if ($snapshot->lifecycle->stateTechnicalName !== 'in_review') {
            return;
        }

        $this->logger->info('This quote was answered but never moved to replied; finishing that transition now.', [
            'quoteId' => $snapshot->identity->quoteId,
        ]);

        $this->reply->send($gateway, $snapshot->identity->quoteId);
    }

    /** A quote the agent has answered before carries the servicing fingerprint. */
    private static function servicedBefore(QuoteSnapshot $snapshot): bool
    {
        return ($snapshot->lifecycle->customFields[ServicingFingerprint::MARKER_KEY] ?? null) !== null;
    }

    /**
     * Public because NegotiationPipeline's gate escalates through it too: its
     * own escalate() was a duplicate of this, and routing both through one
     * method is what keeps the pipeline inside the five-parameter cap once it
     * takes the audit recorder.
     */
    public function escalated(
        QuoteGatewayInterface $gateway,
        QuoteSnapshot $snapshot,
        ?QuoteEscalationReason $reason,
        ?string $extractHash,
        ?string $negotiateHash,
    ): NegotiationPass {
        $reason ??= QuoteEscalationReason::NeedsHumanReview;
        $this->escalator->escalate($gateway, $snapshot, $reason);

        return new NegotiationPass(
            NegotiationOutcome::Escalated,
            $extractHash,
            $negotiateHash,
            escalationReason: $reason,
        );
    }
}
