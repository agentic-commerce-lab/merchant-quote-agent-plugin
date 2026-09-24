<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The record under construction. Public and mutable on purpose: stages append
 * to it as the pass runs, so a pass that dies halfway still carries everything
 * up to the point it died.
 *
 * Deliberately dumb — no behaviour, no validation. DecisionRecorder owns the
 * lifecycle and DecisionRecordWriter owns the mapping.
 *
 * Mirrors the pass-written QuoteDecisionRecord fields. `id` is generated here
 * so trace events and the log line can name the row before it exists. Terminal
 * outcome and merchant-review completion fields are written later by their
 * respective owners. `startedAt` and `trace` are working fields excluded from
 * the record payload.
 *
 * @mago-expect lint:too-many-properties
 * The gate fires above 10 and these properties mirror a table's columns
 * one-for-one. Grouping them would put a translation layer between the draft
 * and the row for no gain.
 */
final class DecisionDraft
{
    /** Generated at construction, not at write time: see the class docblock. */
    public string $id;

    public string $quoteId = '';

    public ?string $quoteNumber = null;

    public ?string $salesChannelId = null;

    public ?string $customerId = null;

    public ?string $currencyIso = null;

    public ?string $triggerReason = null;

    /** The crash-budget counter at pass start, not a delivery number: a thrown pass that is redelivered records 0 again. */
    public ?int $attempt = null;

    public ?string $revisionVersionId = null;

    public ?\DateTimeImmutable $revisionUpdatedAt = null;

    public ?string $band = null;

    public ?string $outcome = null;

    public ?string $escalationReason = null;

    public ?float $discountPercentGranted = null;

    public ?float $maxDiscountPercent = null;

    public ?float $totalNetBefore = null;

    public ?float $totalNetAfter = null;

    public ?string $model = null;

    public ?string $modelHost = null;

    public ?string $extractPromptHash = null;

    public ?string $negotiatePromptHash = null;

    public ?string $replyPromptHash = null;

    public ?int $promptTokens = null;

    public ?int $completionTokens = null;

    /** Excludes a failed retry attempt's time — see `durationMs` for the pass's total wall-clock. */
    public ?int $modelLatencyMs = null;

    public ?int $durationMs = null;

    public ?bool $authorized = null;

    public ?bool $verified = null;

    public ?string $errorClass = null;

    /** @var array<string, mixed>|null */
    public ?array $interpretedAsks = null;

    /** @var array<string, mixed>|null */
    public ?array $historyReads = null;

    public ?string $rawProposal = null;

    /** @var list<string>|null */
    public ?array $violations = null;

    /** @var list<string>|null */
    public ?array $writes = null;

    /** @var list<array<string, string>>|null */
    public ?array $errorChain = null;

    /** The buyer's own words, as this pass read them — see DecisionRecorder::recordBuyerAsk(). */
    public ?string $buyerAsk = null;

    /** The agent's message TO the buyer — see DecisionRecorder::recordReply(). */
    public ?string $replyToBuyer = null;

    /** The prompt version this pass actually sent — see StrategyVersion. */
    public ?string $strategyVersionId = null;

    /** Which rung of the ladder chose that version — see StrategyAssignmentSource. */
    public ?string $strategyAssignmentSource = null;

    /** The DAL version holding a Draft Mode proposal — see Review\DraftingQuoteGateway. */
    public ?string $draftVersionId = null;

    /** Null for an autonomous pass — see ReviewStatus. */
    public ?string $reviewStatus = null;

    /** Buyer input and live pricing when drafted — see Review\ReviewFingerprint. */
    public ?string $reviewFingerprint = null;

    /**
     * The pass's trace events, in order. Not a column: DecisionRecordWriter
     * writes them to `merchant_quote_agent_trace`.
     *
     * @var list<TraceDraft>
     */
    public array $trace = [];

    public float $startedAt = 0.0;

    public function __construct()
    {
        $this->id = Uuid::randomHex();
    }
}
