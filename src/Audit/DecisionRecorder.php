<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Negotiation\AppliedOffer;
use MerchantQuoteAgentPlugin\Negotiation\InterpretedAsk;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationPass;
use MerchantQuoteAgentPlugin\Negotiation\ProposedAnswer;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationDecision;
use MerchantQuoteAgentPlugin\Servicing\Data\PassContext;

/**
 * Collects one servicing pass into a DecisionDraft, then hands it to the
 * writer.
 *
 * Stateful, which is the price of not changing every stage's return type: the
 * stages take this as a constructor dependency and append what they know, so
 * NegotiationPipeline and OfferRound stay inside the five-parameter cap and
 * ModelPlatform can report tokens without changing what it returns.
 *
 * Safe because a pass is never concurrent with another: Messenger handles one
 * message at a time per worker and the servicing lock is held throughout. The
 * invariant that makes it safe ANYWAY is begin(): it resets unconditionally,
 * so a draft left behind by a crashed pass cannot answer for the next quote.
 *
 * One method per collaborator, each taking the value object that collaborator
 * already produces. A setter per field would trip `too-many-methods`, and this
 * is the better API regardless.
 *
 * @mago-expect lint:cyclomatic-complexity
 * The rule aggregates per class (threshold 10) and each record* method
 * contributes exactly one branch: the `$this->draft === null` guard that lets
 * every stage call every method regardless of whether a pass is under way.
 * recordReplyTransitionFailed() is the one that tips this over — it exists
 * because ReplyComposer::send() failing to reach `replied` must show up in
 * the audit record, not just the log, and splitting it into its own class
 * for one more guard clause would be the worse trade.
 */
final class DecisionRecorder
{
    private ?DecisionDraft $draft = null;

    public function __construct(
        private readonly DecisionRecordWriterInterface $writer,
    ) {}

    public function begin(QuoteSnapshot $snapshot, PassContext $context): void
    {
        $draft = new DecisionDraft();
        $draft->quoteId = $snapshot->identity->quoteId;
        $draft->quoteNumber = $snapshot->identity->quoteNumber;
        $draft->salesChannelId = $snapshot->identity->salesChannelId;
        $draft->currencyIso = $snapshot->identity->currencyIso;
        $draft->triggerReason = $context->reason->value;
        $draft->attempt = $context->attempt;
        $draft->revisionVersionId = $snapshot->revision->versionId;
        $draft->revisionUpdatedAt = $snapshot->revision->updatedAt;
        $draft->totalNetBefore = $snapshot->totals->totalNet;
        $draft->startedAt = microtime(true);

        $this->draft = $draft;
    }

    public function recordAsk(InterpretedAsk $ask): void
    {
        if ($this->draft === null) {
            return;
        }

        $this->draft->extractPromptHash = $ask->promptHash;
        $this->draft->interpretedAsks = InterpretationPayload::of($ask->interpretation);
    }

    public function recordDecision(NegotiationDecision $decision, float $maxDiscountPercent): void
    {
        if ($this->draft === null) {
            return;
        }

        $this->draft->band = $decision->overall->value;
        $this->draft->maxDiscountPercent = $maxDiscountPercent;
    }

    public function recordProposal(?string $rawResponse, ProposedAnswer $answer): void
    {
        if ($this->draft === null) {
            return;
        }

        $this->draft->rawProposal = $rawResponse;
        $this->draft->negotiatePromptHash = $answer->promptHash;
        $this->draft->authorized = $answer->offer !== null;

        if ($answer->escalation !== null) {
            $this->draft->escalationReason = $answer->escalation->value;
        }

        // WHY it was refused, not just that it was. Quote 1019 escalated with
        // proposal_rejected and a NULL violations column, so the pass could
        // not be explained afterwards: the reason string reaches
        // ProposedAnswer::escalate() and stopped here, and its log twin is an
        // `info` that prod suppresses. An empty detail stays null -- a model
        // that declined on its own terms broke no rule.
        if ($answer->escalationDetail !== '') {
            $this->draft->violations = [$answer->escalationDetail];
        }
    }

    /** @param list<string> $writes */
    public function recordApplied(AppliedOffer $applied, array $writes): void
    {
        if ($this->draft === null) {
            return;
        }

        $this->draft->verified = $applied->verified;
        $this->draft->violations = $applied->violations;
        $this->draft->writes = $writes;
        $this->draft->totalNetAfter = $applied->after->totals->totalNet;
        $this->draft->discountPercentGranted = GrantedDiscount::of(
            $this->draft->totalNetBefore,
            $applied->after->totals->totalNet,
        );
    }

    public function recordReply(string $comment, ?string $promptHash): void
    {
        if ($this->draft === null) {
            return;
        }

        $this->draft->replyToBuyer = $comment;
        $this->draft->replyPromptHash = $promptHash;
    }

    /**
     * The pass authorized and verified an offer, told the buyer, and STILL
     * did not finish: `replied` is what makes that offer acceptable, and this
     * is the one signal that it was not reached. Appended to `violations`
     * rather than given its own column — the field #21 already reads to
     * explain a pass that needs a human's attention, and this is exactly
     * that, even though nothing here was rejected by policy.
     */
    public function recordReplyTransitionFailed(string $detail): void
    {
        if ($this->draft === null) {
            return;
        }

        $this->draft->violations = [...($this->draft->violations ?? []), $detail];
    }

    public function recordModelCall(
        string $model,
        string $host,
        ?int $promptTokens,
        ?int $completionTokens,
        int $latencyMs,
    ): void {
        if ($this->draft === null) {
            return;
        }

        $this->draft->model = $model;
        $this->draft->modelHost = $host;
        $this->draft->promptTokens = TokenTally::add($this->draft->promptTokens, $promptTokens);
        $this->draft->completionTokens = TokenTally::add($this->draft->completionTokens, $completionTokens);
        $this->draft->modelLatencyMs = (int) $this->draft->modelLatencyMs + $latencyMs;
    }

    /**
     * Clears the draft before writing, so a second finish() cannot write a
     * duplicate and a leaked draft cannot outlive the pass.
     */
    public function finish(?NegotiationPass $pass, ?\Throwable $error = null): void
    {
        $draft = $this->draft;
        $this->draft = null;

        if ($draft === null) {
            return;
        }

        $draft->durationMs = (int) round((microtime(true) - $draft->startedAt) * 1000);
        PassOutcome::applyTo($draft, $pass);
        ErrorChain::applyTo($draft, $error);

        $this->writer->write($draft);
    }
}
