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
 *
 * @mago-expect lint:cyclomatic-complexity
 * This class measured exactly 10 -- the threshold itself, lint-clean -- before
 * the merchant-handover check in `negotiate()` was added. That check is one
 * `if` and contributes exactly one branch, bringing the class to 11. Moving
 * it into its own private method was measured too: the method's own `if` plus
 * the `!== null` check the call site then needs are two branches, not one,
 * bringing the class to 12 -- higher than leaving it inline, not lower. The
 * branch cannot move to another class either: it must run before the extract
 * call (`AskInterpreter::interpret()`) and before the stranded-reply branch
 * that follows it in this same method, both in `negotiate()`, so it has to
 * live here. There is no lower-complexity home for this branch in this class
 * as it stands today.
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
            // Read before finish(): finish() closes the pass and the id with it.
            $decisionId = $this->recorder->decisionId();

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
                'decisionId' => $decisionId,
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
        $conversation = SnapshotAdapter::conversation($snapshot);

        // Before the extract call and before the stranded-reply branch: a
        // human merchant has already answered this quote, and a second reply
        // from the agent — or worse, its line-price writes over theirs — is
        // exactly the surprise this plugin exists to prevent. Not permanent:
        // the buyer's next ask is newer than the merchant's action and
        // re-enables the agent by itself -- except on an open escalation,
        // which stays the human's until they send the quote.
        if (MerchantHandover::tookOver($snapshot, $conversation)) {
            $this->logger->info('A human merchant has this quote (they answered more recently than the buyer '
            . 'asked, or an escalation awaits them); standing down.', [
                'quoteId' => $snapshot->identity->quoteId,
            ]);

            return new NegotiationPass(NegotiationOutcome::HandedOver);
        }

        $ask = $this->interpreter->interpret($settings, $snapshot, $conversation);

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
        // #177: the interpreter can also return a non-null ask that carries
        // NOTHING -- every field null or empty, the "Nice, thanks!" shape --
        // when the extract call ran but correctly found no ask to place.
        // `$ask === null` alone missed that case, because it only covers "no
        // extract call happened at all" (no new buyer comment). Both are the
        // same outcome once a structured ask isn't picking up the slack.
        // Only an OPEN one does: a storefront ask the last pass already
        // answered (countered, say, so `requested_price` still sits below the
        // line) is not new work, and a "thanks" on that quote is acknowledged
        // here rather than sent back to a band with nothing left to move.
        if (($ask === null || $ask->hasNoAsk()) && !StructuredAsk::isOpen($snapshot)) {
            // The one outcome nothing else counts. A comment the agent reads
            // as holding no ask is acknowledged, not escalated (PassedOver) --
            // so if the extract prompt ever regresses, the symptom is real
            // questions getting a polite restatement of the quote, and this
            // line is the only thing that counts them.
            //
            // `commentRead` is what makes the count worth alerting on: false
            // is an ordinary duplicate trigger or a stranded reply, true is a
            // human writing something the agent found no ask in.
            // `acknowledged` says whether they were answered; an escalated
            // quote no longer reaches here (MerchantHandover), so it tracks
            // `commentRead` and stays for this event's readers. The words
            // themselves stay out of the log and go to the audit record
            // instead (`buyer_ask`).
            $pass = PassedOver::handle($gateway, $snapshot, $conversation, $ask, $this->round);

            $this->logger->info('Nothing to answer on this quote.', [
                'quoteId' => $snapshot->identity->quoteId,
                'commentRead' => $ask !== null,
                'acknowledged' => $pass->outcome === NegotiationOutcome::Acknowledged,
            ]);

            return $pass;
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
        // The ask is measured against the ORIGINAL prices, the
        // same anchor the offer checks use — never against the previous
        // round's already-reduced ones. See QuoteBaselineLines::anchor().
        $policySnapshot = SnapshotAdapter::anchored($snapshot);
        $decision = $this->decider->decide(
            $policySnapshot,
            $settings->policy,
            new NegotiationProposal(price: $ask?->interpretation),
        );
        $this->recorder->recordDecision(
            $decision,
            $settings->policy->price->maxDiscountPercent,
            $settings->strategyVersionId,
            $settings->strategyAssignmentSource,
            $settings->policy,
        );

        if ($decision->overall === Band::Escalate) {
            $reason = $decision->price->escalation->reason ?? QuoteEscalationReason::NeedsHumanReview;

            return $this->round->escalated($gateway, $snapshot, $reason, $ask?->promptHash, null);
        }

        return $this->round->play(
            $gateway,
            $snapshot,
            CappedAuthority::forRound($settings, $policySnapshot, SnapshotAdapter::toPolicy($snapshot), $ask),
            $decision,
            $ask,
        );
    }
}
