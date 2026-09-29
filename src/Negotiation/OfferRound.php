<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Policy\AskedDiscountCeiling;
use MerchantQuoteAgentPlugin\Policy\CommentTargetMerger;
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
        ?InterpretedAsk $ask,
    ): NegotiationPass {
        // The ask itself rather than only its prompt hash: the negotiate
        // prompt needs the total the buyer named, in the net space it prices
        // in. sw-ag.dev quote 1055 is why — see NegotiationContext.
        $extractHash = $ask?->promptHash;
        $context = self::context($snapshot, $ask);
        if ($context->customerId === '') {
            $this->logger->warning('The quote carries no customer id; negotiating without account history.', [
                'quoteId' => $snapshot->identity->quoteId,
            ]);
        }
        // Rounding control never rounds the buyer's own figure (spec
        // 2026-09-28, rule 2). This is that figure, measured the way
        // CappedAuthority measured it for this round's cap.
        $asked = AskedDiscountCeiling::percent(SnapshotAdapter::anchored($snapshot), $ask?->interpretation);
        $answer = $this->proposer->propose(
            $settings,
            SnapshotAdapter::toPolicy($snapshot),
            $decision->price,
            $context,
            $asked,
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

        if (
            $answer->offer->price->linePricesNet !== null
            && $context->baseline === null
            && self::servicedBefore($snapshot)
        ) {
            // #49 anchors per-line offers to a stored baseline, so round two
            // is no longer a human's — except on quotes serviced before that
            // baseline existed. Those have no anchor, so a per-line offer on
            // them would still be measured against already-reduced prices.
            // Retires itself as those quotes close.
            $this->logger->info('A per-line ask on a quote with no stored baseline; a human takes it.', [
                'quoteId' => $snapshot->identity->quoteId,
            ]);

            // Issue #169: the model DID propose a per-line offer ($answer->offer
            // is non-null here); the system declines to authorize applying it
            // for want of a baseline to bound it against. That is a policy-layer
            // refusal of a real proposal, the same family ProposalRejected
            // already names below — not an unspecified "needs a human".
            return $this->escalated(
                $gateway,
                $snapshot,
                QuoteEscalationReason::ProposalRejected,
                $extractHash,
                $answer->promptHash,
            );
        }

        $applied = $this->applier->apply($gateway, $snapshot, $settings, $answer->offer, $asked);

        if (!$applied->verified) {
            return $this->unverified($gateway, $applied, $extractHash, $answer->promptHash);
        }

        // What the buyer is told is what the DATABASE says the quote came down
        // by — the offer's own `discountPercent` is null for a per-line
        // concession, and announcing that as a percentage tells the buyer zero.
        //
        // Measured from the BASELINE, not from this round's opening total. Per
        // round the percentages compound and the conversation stops adding up:
        // live quote 1019 was told "2%" and then "3%" while actually receiving
        // 4.94%, so when the buyer asked for "5% at least" they were already
        // 3.81 EUR away from it and nobody could tell. The baseline is the
        // quote as the buyer first saw it, which is the only total they can
        // check a percentage against.
        //
        // #174/#175: only when this pass actually wrote something.
        // $applied->beforeNet is OfferApplier's own pre-write read — never the
        // baseline — so this compares what THIS pass started at against what
        // it ended at. A pass that changed nothing escalates below
        // (no_further_concession): a price ask is never answered with no
        // concession.
        // For a pass that DID write, OfferApplier's never-raise check has
        // already stopped any write above $applied->beforeNet from being
        // accepted as verified, so the baseline figure below can no longer
        // describe an increase as a discount the way it did for quotes 1039
        // and 1048 -- and on the rare case it still disagrees (a stale
        // pass-start baseline race; see ReductionForPass), PostWriteOutcome
        // turns it into an escalation instead of letting the exception reach
        // here.
        //
        // A write that moved nothing (no_further_concession), or a figure
        // that disagrees with a write that already landed
        // (verification_failed): see PostWriteOutcome for both.
        [$reductionPercent, $reason] = PostWriteOutcome::of(
            $this->logger,
            $applied,
            // Not $context->baseline?->totalNet: that baseline was read from
            // the PASS-START snapshot, whose custom fields predate
            // claimAttempt()'s extension for a line added this pass (#54) —
            // the exact staleness anchor() exists to compensate for.
            // anchored() re-extends in memory from $snapshot's own lines, so
            // it is correct even though the stored fragment is stale.
            SnapshotAdapter::anchored($snapshot)->totalNet,
        );

        if ($reason !== null) {
            return $this->escalated($gateway, $applied->after, $reason, $extractHash, $answer->promptHash);
        }

        $replyHash = $this->reply->reply(
            $gateway,
            $applied->after,
            $settings,
            $reductionPercent,
            $context->conversation,
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
     * The state check alone only finds a candidate: a human merchant's quote
     * sits in `in_review` too, with no reply of ours on it, and that one is
     * not ours to finish — the guard below is what tells the two apart. A
     * crash BEFORE the comment write also leaves `in_review`, but then no
     * agent comment exists, the buyer's ask is still the newest, and the
     * retry replays the whole round instead of arriving here at all.
     */
    public function finishStrandedReply(
        QuoteGatewayInterface $gateway,
        QuoteSnapshot $snapshot,
        BuyerConversation $conversation,
    ): void {
        if ($snapshot->lifecycle->stateTechnicalName !== 'in_review') {
            return;
        }

        // The agent's own comment newest IS the stranded shape: #31's worker
        // died between the reply write and the `sent` transition. Only the
        // agent writes an author-less comment (#3, pinned by AddCommentTest),
        // so no human can produce it — and a quote a merchant is holding in
        // in_review, with nothing of ours on it, is not ours to finish.
        if (!$conversation->agentSpokeLast()) {
            return;
        }

        $this->logger->info('This quote was answered but never moved to replied; finishing that transition now.', [
            'quoteId' => $snapshot->identity->quoteId,
        ]);

        $this->reply->send($gateway, $snapshot->identity->quoteId, $snapshot->lifecycle->stateTechnicalName);
    }

    /** Public for PassedOver, which holds this round but not its reply composer. */
    public function acknowledge(QuoteGatewayInterface $gateway, QuoteSnapshot $snapshot): void
    {
        $this->reply->acknowledge($gateway, $snapshot);
    }

    /**
     * Deliberately no rollback: see OfferApplier. We escalate against the
     * post-write snapshot, which is what the buyer now has — the pre-write one
     * when the offer was refused before its write, which is then a rejected
     * proposal rather than a verification failure.
     */
    private function unverified(
        QuoteGatewayInterface $gateway,
        AppliedOffer $applied,
        ?string $extractHash,
        ?string $negotiateHash,
    ): NegotiationPass {
        $this->logger->error('The offer failed verification; escalating.', [
            'quoteId' => $applied->after->identity->quoteId,
            'violations' => $applied->violations,
        ]);

        return $this->escalated($gateway, $applied->after, $applied->escalationReason(), $extractHash, $negotiateHash);
    }

    /** Its own method only to keep play() under mago's halstead-effort warning. */
    private static function context(QuoteSnapshot $snapshot, ?InterpretedAsk $ask): NegotiationContext
    {
        return new NegotiationContext(
            $snapshot->identity->customerId,
            $snapshot->identity->quoteId,
            SnapshotAdapter::conversation($snapshot),
            QuoteBaseline::read($snapshot),
            buyerTargetNet: $ask?->interpretation->price->targetTotal,
            lineAsksNet: (new CommentTargetMerger())->adopted(
                SnapshotAdapter::toPolicy($snapshot),
                $ask?->interpretation,
            ),
            buyerWritesGross: SnapshotAdapter::storedGross($snapshot),
        );
    }

    /** A quote the agent has answered before carries the servicing fingerprint. */
    private static function servicedBefore(QuoteSnapshot $snapshot): bool
    {
        return ServicingFingerprint::stamped($snapshot->lifecycle->customFields) !== null;
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
        // Defensive default only: every caller of escalated() now passes an
        // explicit reason (issue #169 moved the last implicit-null caller to
        // ProposalRejected), so this should never actually fire. Left as
        // NeedsHumanReview rather than removed, because $reason stays
        // nullable for callers this class does not control.
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
