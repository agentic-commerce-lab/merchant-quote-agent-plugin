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
 * One thing that happened during a run: a model call, a policy verdict, a
 * rejected reply, a quote snapshot. The decision record says what a pass
 * concluded; this says how it got there, in enough detail to replay it.
 *
 * A sibling table rather than a JSON column on the decision row: the
 * dashboard list loads up to 500 decision rows with every column, and a
 * pass's prompts are 100-150 KB. No association is declared, like every
 * other entity in this plugin -- `decisionId` is a plain id, read with a
 * filter.
 *
 * `meta` versus `content` is the privacy boundary. `meta` holds only the keys
 * TraceKind::metaKeys() declares and always leaves in an export; `content`
 * holds anything that can carry the buyer's words, a model's words or account
 * data, leaves only with free text, and is what DecisionEraser clears.
 *
 * Write-protected to system scope and without `maxLength:` for the reasons
 * QuoteDecisionRecord's docblock gives; the migration holds the widths.
 */
#[Entity('merchant_quote_agent_trace')]
class TraceEvent extends EntityStruct
{
    #[PrimaryKey]
    #[Field(type: FieldType::UUID, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public string $id = '';

    /** The pass this event belongs to; null outside a pass (PR 2 and 3). */
    #[Field(type: FieldType::UUID, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $decisionId = null;

    #[Field(type: FieldType::UUID, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $quoteId = null;

    #[Field(type: FieldType::UUID, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $customerId = null;

    /** A TraceKind value. */
    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public string $kind = '';

    /** Order within the pass, from 0. */
    #[Field(type: FieldType::INT, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?int $position = null;

    /** When it happened. Pass events are buffered, so this is not `createdAt`. */
    #[Field(type: FieldType::DATETIME, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?\DateTimeImmutable $occurredAt = null;

    /** @var array<string, mixed>|null */
    #[Field(type: FieldType::JSON, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?array $meta = null;

    /** @var array<array-key, mixed>|null */
    #[Field(type: FieldType::JSON, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?array $content = null;
}
