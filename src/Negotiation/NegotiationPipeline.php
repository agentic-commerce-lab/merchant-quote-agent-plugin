<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Policy\Data\Band;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationProposal;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use MerchantQuoteAgentPlugin\Policy\NegotiationDecider;
use MerchantQuoteAgentPlugin\Servicing\Data\PassContext;
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
        private DecisionRecorder $recorder,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws \Throwable rethrown as-is after the pass is recorded, so
     *     Messenger's retry still sees it; nothing here recovers from an
     *     unknown failure.
     */
    #[\Override]
    public function service(
        QuoteSnapshot $snapshot,
        QuoteGatewayInterface $gateway,
        QuoteAgentSettings $settings,
        PassContext $context,
    ): NegotiationOutcome {
        $this->recorder->begin($snapshot, $context);
        $pass = null;
        $error = null;

        try {
            $pass = $this->run($snapshot, $gateway, $settings);

            return $pass->outcome;
        } catch (\Throwable $e) {
            $error = $e;

            throw $e;
        } finally {
            $this->record($pass, $error, $snapshot, $context);
        }
    }

    /** Catches ModelUnavailable; every other throwable belongs to the caller. */
    private function run(
        QuoteSnapshot $snapshot,
        QuoteGatewayInterface $gateway,
        QuoteAgentSettings $settings,
    ): NegotiationPass {
        try {
            return $this->negotiate($snapshot, $gateway, $settings);
        } catch (ModelUnavailable $e) {
            $this->logger->error('The model was unavailable, so this quote goes to a human.', [
                'quoteId' => $snapshot->identity->quoteId,
                'exception' => $e,
            ]);

            return $this->round->escalated($gateway, $snapshot, QuoteEscalationReason::ModelUnavailable, null, null);
        }
    }

    /**
     * The audit write must never fail a pass: a throw here would roll the
     * message back into Messenger's retry and re-answer the buyer, which is
     * the one failure #18 spends the most effort preventing. The structured
     * log event below stays as the backstop when the write is lost.
     *
     * This method runs inside a `finally`, so NOTHING here may throw: a throw
     * from a `finally` replaces whatever was in flight, silently swapping a
     * successful outcome for a failure, or the real error for a logging one.
     * The outer try/catch is the backstop for a logger that itself misbehaves.
     */
    private function record(
        ?NegotiationPass $pass,
        ?\Throwable $error,
        QuoteSnapshot $snapshot,
        PassContext $context,
    ): void {
        try {
            try {
                $this->recorder->finish($pass, $error);
            } catch (\Throwable $e) {
                $this->logger->error('The negotiation pass could not be recorded; the pass itself stands.', [
                    'quoteId' => $snapshot->identity->quoteId,
                    'exception' => $e,
                ]);
            }

            // One structured event per pass, every pass, in scope for this
            // issue: #19 reads these, and #22 needs the hashes to attribute
            // an outcome to the prompt versions that produced it.
            $this->logger->info('A quote negotiation pass finished.', [
                'outcome' => $pass?->outcome->value,
                'trigger' => $context->reason->value,
                'attempt' => $context->attempt,
                'quoteId' => $snapshot->identity->quoteId,
                'salesChannelId' => $snapshot->identity->salesChannelId,
                'extractPromptHash' => $pass?->extractHash,
                'negotiatePromptHash' => $pass?->negotiateHash,
                'replyPromptHash' => $pass?->replyHash,
            ]);
        } catch (\Throwable) {
            // @mago-expect lint:no-empty-catch-clause
            // Deliberately empty: a logger that throws must not take the
            // pass with it, and this method runs in a `finally`, so there is
            // nowhere left to report the failure.
        }
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

        if ($ask->isStructural($snapshot)) {
            // Changing WHAT is being sold is outside a price-and-validity
            // mandate. Nothing downstream acts on these asks either —
            // CommentLineTargets reads lineChanges only for target prices —
            // so without this guard the buyer's real ask is silently dropped.
            $this->logger->info('The buyer asked to change the quote structurally; a human decides that.', [
                'quoteId' => $snapshot->identity->quoteId,
            ]);

            return $this->round->escalated(
                $gateway,
                $snapshot,
                QuoteEscalationReason::NeedsHumanReview,
                $ask->promptHash,
                null,
            );
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

            return $this->round->escalated(
                $gateway,
                $snapshot,
                QuoteEscalationReason::NeedsHumanReview,
                $ask->promptHash,
                null,
            );
        }

        if ($ask->needsClarification()) {
            // The model could not place the ask, so answering it means picking
            // a line at random. Before this gate the pass fell through with an
            // empty ask, landed in the grant band at roughly 0% and sent a
            // generic reply that advanced hasNewBuyerAsk() — so the buyer's
            // real question was answered with a no-op and then never asked
            // again. Which of ask-or-escalate happens is ClarificationRound's
            // call: the marker settles it, and it owns the marker.
            return ClarificationRound::handle($gateway, $snapshot, $ask, $this->round, $this->logger);
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
        $this->recorder->recordDecision($decision, $settings->policy->price->maxDiscountPercent);

        if ($decision->overall === Band::Escalate) {
            $reason = $decision->price->escalation->reason ?? QuoteEscalationReason::NeedsHumanReview;

            return $this->round->escalated($gateway, $snapshot, $reason, $ask->promptHash, null);
        }

        return $this->round->play($gateway, $snapshot, $settings, $decision, $ask->promptHash);
    }
}
