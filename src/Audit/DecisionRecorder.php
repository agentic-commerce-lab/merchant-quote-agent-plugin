<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use MerchantQuoteAgentPlugin\Bridge\Data\History\CustomerSummary;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Negotiation\AppliedOffer;
use MerchantQuoteAgentPlugin\Negotiation\InterpretedAsk;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationPass;
use MerchantQuoteAgentPlugin\Negotiation\ProposedAnswer;
use MerchantQuoteAgentPlugin\Negotiation\Response\HistoryRequest;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationDecision;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use MerchantQuoteAgentPlugin\Policy\Data\Rounding;
use MerchantQuoteAgentPlugin\Servicing\Data\PassContext;
use MerchantQuoteAgentPlugin\Strategy\StrategyAssignmentSource;

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
 * already produces. The history collaborators bring that API above ten
 * methods; splitting the recorder itself would split its single pass lifecycle.
 *
 * @mago-expect lint:too-many-methods
 * Audit owns this per-collaborator API. Revisit if stages start sharing a
 * recording value object or the recorder takes on a second lifecycle.
 *
 * @mago-expect lint:cyclomatic-complexity
 * Retains main's class-level exception for the per-stage null-draft guards,
 * including recordReplyTransitionFailed(). History payload mapping lives in
 * HistoryRecord so it does not add branching to this lifecycle owner.
 */
final class DecisionRecorder
{
    private ?DecisionDraft $draft = null;

    public function __construct(
        private readonly DecisionRecordWriterInterface $writer,
    ) {}

    public function begin(QuoteSnapshot $snapshot, PassContext $context): void
    {
        $this->draft = self::draftFor($snapshot, $context);
    }

    /**
     * One record for an escalation that happened before a pass could start,
     * written whole rather than opened and closed.
     *
     * ServicingPreflight escalates a misconfigured quote -- marker, buyer
     * comment, admin notification -- and returns null, so
     * NegotiationPipeline::service() never runs and the draft lifecycle never
     * opens. Before #35 that action had no row at all: both of #7's pages read
     * this table and nothing else, so the quote was absent rather than
     * incomplete, and #21's outcome counts were short by exactly the
     * misconfigured channels the run exists to find.
     *
     * Deliberately NOT part of the draft lifecycle. It neither reads nor
     * assigns $this->draft, so NegotiationPipeline stays the only class that
     * opens and closes a record and "exactly one record per pass" remains a
     * property of one place. RecorderOwnershipTest is what holds that line:
     * it scans src/ and fails the moment a second class starts a record.
     *
     * Everything a pass would have measured stays null, because none of it
     * happened: no band, no model call, no duration. The problems go to
     * `violations`, which is where recordProposal() already puts an escalation
     * detail and where merchant-quote-agent-detail already renders one -- the
     * `not_configured` snippet has been promising "the technical details below
     * name the fields" to a row that did not exist.
     *
     * @param list<string> $problems the configuration's own complaints; admin-scope
     *                               only, never routed to the buyer-facing comment
     */
    public function recordRefusal(
        QuoteSnapshot $snapshot,
        PassContext $context,
        QuoteEscalationReason $reason,
        array $problems,
    ): void {
        $draft = self::draftFor($snapshot, $context);
        $draft->outcome = NegotiationOutcome::Escalated->value;
        $draft->escalationReason = $reason->value;
        $draft->violations = $problems === [] ? null : $problems;

        $this->writer->write($draft);
    }

    private static function draftFor(QuoteSnapshot $snapshot, PassContext $context): DecisionDraft
    {
        $draft = new DecisionDraft();
        $draft->quoteId = $snapshot->identity->quoteId;
        $draft->quoteNumber = $snapshot->identity->quoteNumber;
        $draft->salesChannelId = $snapshot->identity->salesChannelId;
        $draft->customerId = $snapshot->identity->customerId === '' ? null : $snapshot->identity->customerId;
        $draft->currencyIso = $snapshot->identity->currencyIso;
        $draft->triggerReason = $context->reason->value;
        $draft->attempt = $context->attempt;
        $draft->revisionVersionId = $snapshot->revision->versionId;
        $draft->revisionUpdatedAt = $snapshot->revision->updatedAt;
        $draft->totalNetBefore = $snapshot->totals->totalNet;
        $draft->startedAt = microtime(true);

        // Position 0 of every row, refusals included: what the quote looked
        // like when the agent picked it up is the one thing every later event
        // is read against. Content, nearly all of it -- product labels and the
        // quote's own identity are in there (see QuoteTrace for what is not).
        [$meta, $content] = QuoteTrace::of($snapshot);
        TraceDraft::appendTo($draft, TraceKind::QuoteBefore, $meta, $content);

        return $draft;
    }

    public function recordHistorySummary(CustomerSummary $summary): void
    {
        HistoryRecord::summaryTo($this->draft, $summary);
    }

    public function recordHistoryRound(HistoryRequest $request, string $result): void
    {
        HistoryRecord::roundTo($this->draft, $request, $result);
    }

    /**
     * One event for the open pass's trace (see TraceKind). For the
     * collaborators that are not the recorder's to map -- ModelPlatform and
     * ReplyComposer build their own event. Dropped when no pass is open, like
     * every other record* call.
     *
     * @param array<string, mixed> $meta
     * @param array<array-key, mixed>|null $content
     */
    public function trace(TraceKind $kind, array $meta, ?array $content = null): void
    {
        if ($this->draft === null) {
            return;
        }

        TraceDraft::appendTo($this->draft, $kind, $meta, $content);
    }

    /** The id the open pass's row will have, or null when no pass is open. */
    public function decisionId(): ?string
    {
        return $this->draft?->id;
    }

    /**
     * The buyer's comment this pass is about to read, recorded BEFORE the
     * extract call so a model that fails, or one that finds nothing, still
     * leaves the question behind.
     *
     * That ordering is the point of the column. An extraction empty in every
     * field ends the pass as `Acknowledged` — the quote restated, back to
     * `replied` — and the servicing fingerprint is stamped whatever the
     * outcome, so a mis-read question gets a restatement instead of an
     * answer, and `interpreted_asks` records the emptiness that caused it, not
     * the words that were passed over. Silent `NothingToDo` remains for a pass
     * with no comment read or on an escalated quote.
     */
    public function recordBuyerAsk(string $comment): void
    {
        if ($this->draft === null) {
            return;
        }

        $this->draft->buyerAsk = $comment;
    }

    public function recordAsk(InterpretedAsk $ask): void
    {
        if ($this->draft === null) {
            return;
        }

        $this->draft->extractPromptHash = $ask->promptHash;
        $this->draft->interpretedAsks = InterpretationPayload::of($ask->interpretation);
    }

    public function recordDecision(
        NegotiationDecision $decision,
        float $maxDiscountPercent,
        ?string $strategyVersionId = null,
        ?StrategyAssignmentSource $strategyAssignmentSource = null,
    ): void {
        if ($this->draft === null) {
            return;
        }

        $this->draft->band = $decision->overall->value;
        $this->draft->maxDiscountPercent = $maxDiscountPercent;
        $this->draft->strategyVersionId = $strategyVersionId;
        $this->draft->strategyAssignmentSource = $strategyAssignmentSource?->value;

        [$meta, $content] = VerdictTrace::of($decision);
        TraceDraft::appendTo($this->draft, TraceKind::PolicyVerdict, $meta, $content);
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

    /**
     * What rounding control did to this pass's offer (spec 2026-09-28). Its
     * own event rather than a key on `policy_verdict`: that one is recorded
     * before the model has proposed anything there is to round. Null means
     * rounding did not run and records nothing. Only enums and figures, so
     * all of it is meta.
     */
    public function recordRounding(?Rounding $rounding): void
    {
        if ($rounding === null || $this->draft === null) {
            return;
        }

        TraceDraft::appendTo(
            $this->draft,
            TraceKind::Rounding,
            [
                'mode' => $rounding->mode->value,
                'step' => $rounding->step,
                'unrounded' => $rounding->unrounded,
                'rounded' => $rounding->rounded,
                'skipped' => $rounding->skipped?->value,
            ],
            null,
        );
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

        [$meta, $content] = QuoteTrace::of($applied->after);
        TraceDraft::appendTo($this->draft, TraceKind::QuoteAfter, $meta, $content);
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
