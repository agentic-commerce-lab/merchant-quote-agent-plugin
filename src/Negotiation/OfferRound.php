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
        $answer = $this->proposer->propose(
            $settings,
            SnapshotAdapter::toPolicy($snapshot),
            $decision->price,
            $conversation,
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

        if ($answer->offer->price->linePricesNet !== null && $conversation->agent !== []) {
            // #2(a), the half still open: the reference lines a per-line offer
            // is bounded against are re-captured every round, so round two is
            // measured against round one's already-reduced prices and
            // compounds straight past the cap — with the authorizer and the
            // verifier both clean. Persisting that reference across passes
            // needs a customField; until then round two is a human's.
            $this->logger->info('A per-line ask reached a second round; a human takes it until #2(a) lands.', [
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

    private function escalated(
        QuoteGatewayInterface $gateway,
        QuoteSnapshot $snapshot,
        ?QuoteEscalationReason $reason,
        ?string $extractHash,
        ?string $negotiateHash,
    ): NegotiationPass {
        $this->escalator->escalate($gateway, $snapshot, $reason ?? QuoteEscalationReason::NeedsHumanReview);

        return new NegotiationPass(NegotiationOutcome::Escalated, $extractHash, $negotiateHash);
    }
}
