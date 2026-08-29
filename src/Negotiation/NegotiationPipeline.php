<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Policy\Data\Band;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationProposal;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use MerchantQuoteAgentPlugin\Policy\NegotiationDecider;
use MerchantQuoteAgentPlugin\Servicing\QuoteEscalator;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingPipelineInterface;
use Psr\Log\LoggerInterface;

/**
 * snapshot → interpret → classify → propose → apply/verify → reply.
 *
 * The classification step is a gate, not a formality: an ask the bands put out
 * of authority escalates HERE, before the negotiate and reply calls are paid
 * for, and that holds even when the model is unreachable.
 *
 * Every failure escalates. There is no fall back to rules-only on error — a
 * shop whose negotiation quietly changes character when a provider has a bad
 * minute is the silent behaviour change this design exists to remove.
 */
final readonly class NegotiationPipeline implements QuoteServicingPipelineInterface
{
    public function __construct(
        private AskInterpreter $interpreter,
        private NegotiationDecider $decider,
        private OfferRound $round,
        private QuoteEscalator $escalator,
        private LoggerInterface $logger,
    ) {}

    #[\Override]
    public function service(
        QuoteSnapshot $snapshot,
        QuoteGatewayInterface $gateway,
        QuoteAgentSettings $settings,
    ): NegotiationOutcome {
        try {
            return $this->negotiate($snapshot, $gateway, $settings);
        } catch (ModelUnavailable $e) {
            $this->logger->error('The model was unavailable, so this quote goes to a human.', [
                'quoteId' => $snapshot->identity->quoteId,
                'exception' => $e,
            ]);

            return $this->escalate($gateway, $snapshot, QuoteEscalationReason::ModelUnavailable);
        }
    }

    /** @throws ModelUnavailable */
    private function negotiate(
        QuoteSnapshot $snapshot,
        QuoteGatewayInterface $gateway,
        QuoteAgentSettings $settings,
    ): NegotiationOutcome {
        $ask = $this->interpreter->interpret($settings, $snapshot, SnapshotAdapter::conversation($snapshot));

        if ($ask === null) {
            return NegotiationOutcome::NothingToDo;
        }

        if ($ask->isStructural()) {
            // Changing WHAT is being sold is outside a price-and-validity
            // mandate. Nothing downstream acts on these asks either —
            // CommentLineTargets reads lineChanges only for target prices —
            // so without this guard the buyer's real ask is silently dropped.
            $this->logger->info('The buyer asked to change the quote structurally; a human decides that.', [
                'quoteId' => $snapshot->identity->quoteId,
            ]);

            return $this->escalate($gateway, $snapshot, QuoteEscalationReason::NeedsHumanReview);
        }

        // `overall` IS the price band here: nothing composes a non-price ask
        // into the proposal, so NegotiationDecider aggregates the price band
        // with a bandless (granting) non-price decision. Reading its own
        // aggregate rather than re-running PriceBandClassifier over `->price`
        // keeps one classification, and means the day a non-price ask does
        // reach the decider the gate already accounts for it.
        $decision = $this->decider->decide(
            SnapshotAdapter::toPolicy($snapshot),
            $settings->policy,
            new NegotiationProposal(price: $ask->interpretation),
        );

        if ($decision->overall === Band::Escalate) {
            $reason = $decision->price->escalation->reason ?? QuoteEscalationReason::NeedsHumanReview;

            return $this->escalate($gateway, $snapshot, $reason);
        }

        return $this->round->play($gateway, $snapshot, $settings, $decision->price, $decision->overall);
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
