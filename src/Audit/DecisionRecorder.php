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
 * ChatCompletionClient can report tokens without changing what it returns.
 *
 * Safe because a pass is never concurrent with another: Messenger handles one
 * message at a time per worker and the servicing lock is held throughout. The
 * invariant that makes it safe ANYWAY is begin(): it resets unconditionally,
 * so a draft left behind by a crashed pass cannot answer for the next quote.
 *
 * One method per collaborator, each taking the value object that collaborator
 * already produces. A setter per field would trip `too-many-methods`, and this
 * is the better API regardless.
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

    public function recordProposal(string $rawResponse, ProposedAnswer $answer): void
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
    }

    public function recordReply(string $comment, ?string $promptHash): void
    {
        if ($this->draft === null) {
            return;
        }

        $this->draft->buyerComment = $comment;
        $this->draft->replyPromptHash = $promptHash;
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
        $this->draft->promptTokens = (int) $this->draft->promptTokens + (int) $promptTokens;
        $this->draft->completionTokens = (int) $this->draft->completionTokens + (int) $completionTokens;
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
