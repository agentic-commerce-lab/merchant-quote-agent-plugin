<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Policy\Data\Band;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteDecision;
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
        QuoteDecision $decision,
        Band $band,
    ): NegotiationOutcome {
        $conversation = SnapshotAdapter::conversation($snapshot);
        $answer = $this->proposer->propose($settings, SnapshotAdapter::toPolicy($snapshot), $decision, $conversation);

        if ($answer->offer === null) {
            // The detail stays here, in the log: QuoteEscalator writes to the
            // conversation the BUYER reads, so nothing internal may travel.
            $this->logger->info('No offer was proposed for this quote; a human takes it.', [
                'quoteId' => $snapshot->identity->quoteId,
                'detail' => $answer->escalationDetail,
            ]);

            return $this->escalate($gateway, $snapshot, $answer->escalation ?? QuoteEscalationReason::NeedsHumanReview);
        }

        $applied = $this->applier->apply($gateway, $snapshot, $settings, $answer->offer);

        if (!$applied->verified) {
            $this->logger->error('The database disagreed with the offer we applied; escalating.', [
                'quoteId' => $snapshot->identity->quoteId,
                'violations' => $applied->violations,
            ]);

            // Deliberately no rollback: see OfferApplier. We escalate against
            // the post-write snapshot, which is what the buyer now has.
            return $this->escalate($gateway, $applied->after, QuoteEscalationReason::VerificationFailed);
        }

        $this->reply->reply($gateway, $applied->after, $settings, $answer->offer, $conversation);

        return $band === Band::Counter ? NegotiationOutcome::Countered : NegotiationOutcome::Offered;
    }

    private function escalate(
        QuoteGatewayInterface $gateway,
        QuoteSnapshot $snapshot,
        QuoteEscalationReason $reason,
    ): NegotiationOutcome {
        $this->escalator->escalate($gateway, $snapshot, $reason);

        return NegotiationOutcome::Escalated;
    }
}
