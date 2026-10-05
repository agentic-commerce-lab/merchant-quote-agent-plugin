<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\FieldType;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Protection;
use Shopware\Core\Framework\DataAbstractionLayer\Entity as EntityStruct;

/**
 * One row per servicing pass of a quote: what the buyer asked, what the rules
 * allowed, what the model proposed, what was written, and what the buyer was
 * told. #21 reads its whole result set off this table and #7 renders it.
 *
 * Scalar where the field is aggregated or filtered, JSON where it is only
 * read: DAL cannot aggregate inside a JSON column, so anything #21 counts or
 * averages has to be its own column.
 *
 * `terminalState`, `terminalAt`, `resolvedAt` and `resolvedState` are four of
 * the ten columns not written by a servicing pass. TerminalOutcomeSubscriber
 * stamps the first two later, via TerminalOutcomeWriter, when the quote
 * reaches one of the five states that end a negotiation, so a record's insert
 * still has exactly one owner and the outcome is a separate update. The other
 * six — `reviewedAt`, `sentReply`, `sentChanges`, `feedbackReasons`,
 * `feedbackComment` and `feedbackAt` — are written by DecisionReviewStore when
 * a merchant sends, rejects or comments on a Draft Mode draft.
 *
 * Write-protected to system scope on every field: DecisionRecordWriter writes
 * through Context::createDefaultContext(), which is system scope, while
 * admin-API requests are user/crud scope. So the plugin can write its own
 * record and nobody can PATCH it afterwards. This does NOT cover DELETE —
 * WriteProtected is enforced during field encoding and a delete encodes no
 * fields — which is why the admin module's ACL grants delete only to an
 * explicit deleter role.
 *
 * No `maxLength:` on the string fields, deliberately: the argument does not
 * exist on `Attribute\Field` at the 6.7.1 support floor (checked absent up to
 * and including 6.7.4.2, present by 6.7.13.1), and a named argument for a
 * parameter the installed core lacks is an `Error` at attribute instantiation
 * — which happens during the container build, so it takes the whole shop
 * down, not just this plugin. The column widths live in
 * Migration1787998662CreateQuoteAgentDecision, which is the constraint that
 * actually holds; the attribute argument only added a second, redundant
 * Length validator on top of it. CoreFloorCompatibilityTest guards the floor.
 *
 * @mago-expect lint:too-many-properties
 * The gate fires above 10 and these properties ARE the table's columns. The
 * rule's own remedy — group them into an object — is what a DAL entity cannot
 * do, and pushing them into JSON to duck it would cost the aggregation this
 * record exists for.
 */
#[Entity('merchant_quote_agent_decision')]
class QuoteDecisionRecord extends EntityStruct
{
    #[PrimaryKey]
    #[Field(type: FieldType::UUID, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public string $id = '';

    #[Field(type: FieldType::UUID, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public string $quoteId = '';

    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $quoteNumber = null;

    #[Field(type: FieldType::UUID, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $salesChannelId = null;

    #[Field(type: FieldType::UUID, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $customerId = null;

    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $currencyIso = null;

    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $triggerReason = null;

    /** The crash-budget counter at pass start, not a delivery number: a thrown pass that is redelivered records 0 again. */
    #[Field(type: FieldType::INT, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?int $attempt = null;

    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $revisionVersionId = null;

    #[Field(type: FieldType::DATETIME, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?\DateTimeImmutable $revisionUpdatedAt = null;

    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $band = null;

    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $outcome = null;

    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $escalationReason = null;

    #[Field(type: FieldType::FLOAT, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?float $discountPercentGranted = null;

    #[Field(type: FieldType::FLOAT, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?float $maxDiscountPercent = null;

    #[Field(type: FieldType::FLOAT, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?float $totalNetBefore = null;

    #[Field(type: FieldType::FLOAT, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?float $totalNetAfter = null;

    /**
     * Shopware's `amountTotal`, tax included: the figure `replyToBuyer` states
     * (`QuoteTotals::buyerFacingTotal()`). Read off the same snapshots as the
     * net pair. Null on rows before 2026-09-28.
     */
    #[Field(type: FieldType::FLOAT, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?float $totalGrossBefore = null;

    #[Field(type: FieldType::FLOAT, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?float $totalGrossAfter = null;

    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $model = null;

    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $modelHost = null;

    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $extractPromptHash = null;

    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $negotiatePromptHash = null;

    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $replyPromptHash = null;

    #[Field(type: FieldType::INT, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?int $promptTokens = null;

    #[Field(type: FieldType::INT, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?int $completionTokens = null;

    /** Excludes a failed retry attempt's time — see `durationMs` for the pass's total wall-clock. */
    #[Field(type: FieldType::INT, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?int $modelLatencyMs = null;

    #[Field(type: FieldType::INT, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?int $durationMs = null;

    #[Field(type: FieldType::BOOL, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?bool $authorized = null;

    #[Field(type: FieldType::BOOL, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?bool $verified = null;

    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $errorClass = null;

    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $terminalState = null;

    #[Field(type: FieldType::DATETIME, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?\DateTimeImmutable $terminalAt = null;

    /**
     * When a human first acted on the quote after this pass escalated, and the
     * state they moved it to — or `commented` when a merchant answered in the
     * quote's thread (QA-05). Written by EscalationResolutionSubscriber and
     * MerchantCommentResolutionSubscriber, never by a servicing pass.
     *
     * Any transition by anyone counts, including the buyer withdrawing the
     * quote: the core state-change event carries no author, and the only thing
     * that does — SwagCommercial's `quote_history` — does not exist on 7.12.
     * A comment counts only when an admin user wrote it. `resolvedState` is
     * stored precisely so this stays inspectable.
     */
    #[Field(type: FieldType::DATETIME, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?\DateTimeImmutable $resolvedAt = null;

    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $resolvedState = null;

    /** @var array<string, mixed>|null */
    #[Field(type: FieldType::JSON, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?array $interpretedAsks = null;

    /** @var array<string, mixed>|null */
    #[Field(type: FieldType::JSON, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?array $historyReads = null;

    #[Field(type: FieldType::TEXT, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $rawProposal = null;

    /** @var list<string>|null */
    #[Field(type: FieldType::JSON, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?array $violations = null;

    /** @var list<string>|null */
    #[Field(type: FieldType::JSON, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?array $writes = null;

    /** @var list<array<string, string>>|null */
    #[Field(type: FieldType::JSON, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?array $errorChain = null;

    /**
     * The buyer's own words: the one comment this pass read, stored verbatim
     * and written by DecisionRecorder::recordBuyerAsk() alone.
     *
     * Null for a pass that read no comment — a structured-only per-line ask, a
     * re-trigger with nothing new, a preflight refusal — which is the same
     * "no extract call happened" the null `extract_prompt_hash` beside it says.
     *
     * Here because of #177's trade-off: an extraction empty in every field
     * consumes the comment, so a model that mis-reads a real question as empty
     * never answers it. Such a pass is now `acknowledged` — the quote restated
     * and back in `replied` — and silent `nothing_to_do` remains for a pass
     * with no comment read or on an escalated quote; reviewing passed-over
     * comments means reading both. That is acceptable only while a merchant
     * can SEE what was passed over, and until this column existed those rows
     * held the buyer's ask in `interpreted_asks` — which is precisely null on
     * them — and nowhere else. Reviewing them meant opening each quote and
     * matching by timestamp.
     *
     * Deliberately NOT named `buyer_comment`: that column existed until
     * 2026-09-04 holding the agent's reply, and a name that once meant the
     * opposite is not one to reuse on an audit table anyone reads historically.
     */
    #[Field(type: FieldType::TEXT, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $buyerAsk = null;

    /**
     * The agent's message TO the buyer, written by
     * DecisionRecorder::recordReply() and by nothing else. Named `buyer_comment`
     * until 2026-09-04, which read as the buyer speaking; the buyer's own words
     * are in `buyer_ask` above, and were nowhere at all until 2026-09-22.
     */
    #[Field(type: FieldType::TEXT, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $replyToBuyer = null;

    /** The prompt version this pass actually sent — see StrategyVersion. */
    #[Field(type: FieldType::UUID, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $strategyVersionId = null;

    /** Which rung of the ladder chose that version — see StrategyAssignmentSource. */
    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $strategyAssignmentSource = null;

    /** The DAL version holding a pending draft's prices; null once sent, rejected or superseded. */
    #[Field(type: FieldType::UUID, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $draftVersionId = null;

    /** See ReviewStatus. Null for an autonomous pass. */
    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $reviewStatus = null;

    /** Internal buyer/pricing staleness check for Send — see Review\ReviewFingerprint. Never exported. */
    #[Field(type: FieldType::TEXT, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $reviewFingerprint = null;

    /** When the merchant sent or rejected the draft. */
    #[Field(type: FieldType::DATETIME, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?\DateTimeImmutable $reviewedAt = null;

    /** The text the merchant actually sent — compare with replyToBuyer, the draft. */
    #[Field(type: FieldType::TEXT, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $sentReply = null;

    /** @var array<string, mixed>|null {discountPercent, totalNet, totalGross, expiresAt, editedByMerchant} */
    #[Field(type: FieldType::JSON, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?array $sentChanges = null;

    /** @var list<string>|null Review\FeedbackReason values */
    #[Field(type: FieldType::JSON, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?array $feedbackReasons = null;

    /** The merchant's own words on why the agent's work was not right. */
    #[Field(type: FieldType::TEXT, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $feedbackComment = null;

    #[Field(type: FieldType::DATETIME, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?\DateTimeImmutable $feedbackAt = null;
}
