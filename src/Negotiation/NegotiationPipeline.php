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
            $pass = $this->negotiate($snapshot, $gateway, $settings);
        } catch (ModelUnavailable $e) {
            $this->logger->error('The model was unavailable, so this quote goes to a human.', [
                'quoteId' => $snapshot->identity->quoteId,
                'exception' => $e,
            ]);

            $pass = $this->escalate($gateway, $snapshot, QuoteEscalationReason::ModelUnavailable);
        }

        // One structured event per pass, every pass, in scope for this issue:
        // #19 reads these, and #22 needs the hashes to attribute an outcome to
        // the prompt versions that produced it.
        $this->logger->info('A quote negotiation pass finished.', [
            'outcome' => $pass->outcome->value,
            'quoteId' => $snapshot->identity->quoteId,
            'salesChannelId' => $snapshot->identity->salesChannelId,
            'extractPromptHash' => $pass->extractHash,
            'negotiatePromptHash' => $pass->negotiateHash,
            'replyPromptHash' => $pass->replyHash,
        ]);

        return $pass->outcome;
    }

    /** @throws ModelUnavailable */
    private function negotiate(
        QuoteSnapshot $snapshot,
        QuoteGatewayInterface $gateway,
        QuoteAgentSettings $settings,
    ): NegotiationPass {
        $ask = $this->interpreter->interpret($settings, $snapshot, SnapshotAdapter::conversation($snapshot));

        if ($ask === null) {
            $this->round->finishStrandedReply($gateway, $snapshot);

            return new NegotiationPass(NegotiationOutcome::NothingToDo);
        }

        if ($ask->isStructural()) {
            // Changing WHAT is being sold is outside a price-and-validity
            // mandate. Nothing downstream acts on these asks either —
            // CommentLineTargets reads lineChanges only for target prices —
            // so without this guard the buyer's real ask is silently dropped.
            $this->logger->info('The buyer asked to change the quote structurally; a human decides that.', [
                'quoteId' => $snapshot->identity->quoteId,
            ]);

            return $this->escalate($gateway, $snapshot, QuoteEscalationReason::NeedsHumanReview, $ask->promptHash);
        }

        if ($ask->hasNonPriceAsk()) {
            // Shipping, payment terms and bundles are extracted and then go
            // nowhere: only `price` is composed into the proposal below, and
            // QuoteUpdate cannot write a delivery term anyway. Answering the
            // price half and dropping the rest silently is worse than saying
            // a human takes it — and promising shipping that never lands is
            // worse still, which is why this does not route through
            // NonPriceTermsDecider.
            $this->logger->info('The buyer asked for a non-price term; a human decides that.', [
                'quoteId' => $snapshot->identity->quoteId,
            ]);

            return $this->escalate($gateway, $snapshot, QuoteEscalationReason::NeedsHumanReview, $ask->promptHash);
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

            return $this->escalate($gateway, $snapshot, $reason, $ask->promptHash);
        }

        return $this->round->play($gateway, $snapshot, $settings, $decision, $ask->promptHash);
    }

    private function escalate(
        QuoteGatewayInterface $gateway,
        QuoteSnapshot $snapshot,
        QuoteEscalationReason $reason,
        ?string $extractHash = null,
    ): NegotiationPass {
        $this->escalator->escalate($gateway, $snapshot, $reason);

        return new NegotiationPass(NegotiationOutcome::Escalated, $extractHash);
    }
}
