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
 * `terminalState` and `terminalAt` are the only two columns not written by a
 * servicing pass. TerminalOutcomeSubscriber stamps them later, when the quote
 * reaches one of the five states that end a negotiation, so a record's insert
 * still has exactly one owner and the outcome is a separate update.
 *
 * Write-protected to system scope on every field: DecisionRecordWriter writes
 * through Context::createDefaultContext(), which is system scope, while
 * admin-API requests are user/crud scope. So the plugin can write its own
 * record and nobody can PATCH it afterwards. This does NOT cover DELETE —
 * WriteProtected is enforced during field encoding and a delete encodes no
 * fields — which is why the admin module's ACL grants delete only to an
 * explicit deleter role.
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

    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false], maxLength: 64)]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $quoteNumber = null;

    #[Field(type: FieldType::UUID, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $salesChannelId = null;

    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false], maxLength: 3)]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $currencyIso = null;

    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false], maxLength: 64)]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $triggerReason = null;

    /** The crash-budget counter at pass start, not a delivery number: a thrown pass that is redelivered records 0 again. */
    #[Field(type: FieldType::INT, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?int $attempt = null;

    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false], maxLength: 64)]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $revisionVersionId = null;

    #[Field(type: FieldType::DATETIME, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?\DateTimeImmutable $revisionUpdatedAt = null;

    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false], maxLength: 32)]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $band = null;

    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false], maxLength: 32)]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $outcome = null;

    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false], maxLength: 64)]
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

    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false], maxLength: 128)]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $model = null;

    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $modelHost = null;

    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false], maxLength: 64)]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $extractPromptHash = null;

    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false], maxLength: 64)]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $negotiatePromptHash = null;

    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false], maxLength: 64)]
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

    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false], maxLength: 64)]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $terminalState = null;

    #[Field(type: FieldType::DATETIME, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?\DateTimeImmutable $terminalAt = null;

    /** @var array<string, mixed>|null */
    #[Field(type: FieldType::JSON, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?array $interpretedAsks = null;

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

    #[Field(type: FieldType::TEXT, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $buyerComment = null;
}
