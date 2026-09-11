<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\History\CrossCustomerRead;
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

    /** Known model and account-boundary failures escalate; other failures belong to the caller. */
    private function run(
        QuoteSnapshot $snapshot,
        QuoteGatewayInterface $gateway,
        QuoteAgentSettings $settings,
    ): NegotiationPass {
        try {
            return $this->negotiate($snapshot, $gateway, $settings);
        } catch (ModelUnavailable|CrossCustomerRead $e) {
            return (new NegotiationFailure($this->round, $this->recorder, $this->logger))->escalate(
                $gateway,
                $snapshot,
                $e,
            );
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

        // A comment is not the only way to ask. The storefront writes a
        // per-line target into `quote_line_item.requested_price`, and a buyer
        // who fills it in need not type anything: AskInterpreter then returns
        // null for want of a comment to interpret, and reading that alone as
        // "nothing to do" recorded a real price ask as `nothing_to_do`, with
        // no band and no model call. The policy layer never needed the
        // comment — `NegotiationProposal::$price` is nullable,
        // `CommentTargetMerger::merge()` deliberately leaves the line's own
        // target standing when there is no interpretation, and the appliers
        // read `requestedUnitPrice` directly — so the ask only ever failed to
        // reach it, which is what SnapshotAdapter's docblock already promises
        // it does.
        if ($ask === null && !StructuredAsk::isUnmet($snapshot)) {
            $this->round->finishStrandedReply($gateway, $snapshot);

            return new NegotiationPass(NegotiationOutcome::NothingToDo);
        }

        // Before the gate on purpose: an escalated or clarified pass must
        // leave the buyer's number on the line too. See AskMirror.
        AskMirror::mirror($gateway, $snapshot, $ask, $this->logger);

        $refusal = $ask === null ? null : AskGate::refuse($gateway, $snapshot, $ask, $this->round, $this->logger);

        if ($refusal !== null) {
            return $refusal;
        }

        return $this->answer($ask, $snapshot, $gateway, $settings);
    }

    /**
     * Classify the ask, then answer it or hand it over.
     *
     * `$ask` is null for a structured-only ask, and every hash it would supply
     * is nullable for exactly that case: no extraction prompt ran, and
     * recording one that did not is the kind of claim this plugin's audit trail
     * exists to avoid.
     */
    private function answer(
        ?InterpretedAsk $ask,
        QuoteSnapshot $snapshot,
        QuoteGatewayInterface $gateway,
        QuoteAgentSettings $settings,
    ): NegotiationPass {
        // `overall` IS the price band here: nothing composes a non-price ask
        // into the proposal, so NegotiationDecider aggregates the price band
        // with a bandless (granting) non-price decision. Reading its own
        // aggregate rather than re-running PriceBandClassifier over `->price`
        // keeps one classification, and means the day a non-price ask does
        // reach the decider the gate already accounts for it.
        // Quote 1101: the ask is measured against the ORIGINAL prices, the
        // same anchor the offer checks use — never against the previous
        // round's already-reduced ones. See QuoteBaselineLines::anchor().
        $policySnapshot = SnapshotAdapter::anchored($snapshot);
        $decision = $this->decider->decide(
            $policySnapshot,
            $settings->policy,
            new NegotiationProposal(price: $ask?->interpretation),
        );
        $this->recorder->recordDecision($decision, $settings->policy->price->maxDiscountPercent);

        if ($decision->overall === Band::Escalate) {
            $reason = $decision->price->escalation->reason ?? QuoteEscalationReason::NeedsHumanReview;

            return $this->round->escalated($gateway, $snapshot, $reason, $ask?->promptHash, null);
        }

        return $this->round->play(
            $gateway,
            $snapshot,
            CappedAuthority::forRound($settings, $policySnapshot, $ask),
            $decision,
            $ask?->promptHash,
        );
    }
}
