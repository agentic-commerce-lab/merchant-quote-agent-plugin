<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\FieldType;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\PrimaryKey;
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
 * `terminalState` and `terminalAt` are reserved and never written here. The
 * subscriber that fills them is a follow-up; the columns exist so that
 * follow-up needs no migration.
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
    #[Field(type: FieldType::UUID, api: true)]
    public string $id = '';

    #[Field(type: FieldType::UUID, api: true)]
    public string $quoteId = '';

    #[Field(type: FieldType::STRING, api: true)]
    public ?string $quoteNumber = null;

    #[Field(type: FieldType::UUID, api: true)]
    public ?string $salesChannelId = null;

    #[Field(type: FieldType::STRING, api: true)]
    public ?string $currencyIso = null;

    #[Field(type: FieldType::STRING, api: true)]
    public ?string $triggerReason = null;

    #[Field(type: FieldType::INT, api: true)]
    public ?int $attempt = null;

    #[Field(type: FieldType::STRING, api: true)]
    public ?string $revisionVersionId = null;

    #[Field(type: FieldType::DATETIME, api: true)]
    public ?\DateTimeImmutable $revisionUpdatedAt = null;

    #[Field(type: FieldType::STRING, api: true)]
    public ?string $band = null;

    #[Field(type: FieldType::STRING, api: true)]
    public ?string $outcome = null;

    #[Field(type: FieldType::STRING, api: true)]
    public ?string $escalationReason = null;

    #[Field(type: FieldType::FLOAT, api: true)]
    public ?float $discountPercentGranted = null;

    #[Field(type: FieldType::FLOAT, api: true)]
    public ?float $maxDiscountPercent = null;

    #[Field(type: FieldType::FLOAT, api: true)]
    public ?float $totalNetBefore = null;

    #[Field(type: FieldType::FLOAT, api: true)]
    public ?float $totalNetAfter = null;

    #[Field(type: FieldType::STRING, api: true)]
    public ?string $model = null;

    #[Field(type: FieldType::STRING, api: true)]
    public ?string $modelHost = null;

    #[Field(type: FieldType::STRING, api: true)]
    public ?string $extractPromptHash = null;

    #[Field(type: FieldType::STRING, api: true)]
    public ?string $negotiatePromptHash = null;

    #[Field(type: FieldType::STRING, api: true)]
    public ?string $replyPromptHash = null;

    #[Field(type: FieldType::INT, api: true)]
    public ?int $promptTokens = null;

    #[Field(type: FieldType::INT, api: true)]
    public ?int $completionTokens = null;

    #[Field(type: FieldType::INT, api: true)]
    public ?int $modelLatencyMs = null;

    #[Field(type: FieldType::INT, api: true)]
    public ?int $durationMs = null;

    #[Field(type: FieldType::BOOL, api: true)]
    public ?bool $authorized = null;

    #[Field(type: FieldType::BOOL, api: true)]
    public ?bool $verified = null;

    #[Field(type: FieldType::STRING, api: true)]
    public ?string $errorClass = null;

    #[Field(type: FieldType::STRING, api: true)]
    public ?string $terminalState = null;

    #[Field(type: FieldType::DATETIME, api: true)]
    public ?\DateTimeImmutable $terminalAt = null;

    /** @var array<string, mixed>|null */
    #[Field(type: FieldType::JSON, api: true)]
    public ?array $interpretedAsks = null;

    #[Field(type: FieldType::TEXT, api: true)]
    public ?string $rawProposal = null;

    /** @var list<string>|null */
    #[Field(type: FieldType::JSON, api: true)]
    public ?array $violations = null;

    /** @var list<string>|null */
    #[Field(type: FieldType::JSON, api: true)]
    public ?array $writes = null;

    /** @var list<array<string, string>>|null */
    #[Field(type: FieldType::JSON, api: true)]
    public ?array $errorChain = null;

    #[Field(type: FieldType::TEXT, api: true)]
    public ?string $buyerComment = null;
}
