<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\FieldType;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Entity as EntityStruct;

/**
 * One nightly self-improvement run, for one sales channel.
 *
 * Written even when the night produced nothing, because silence is
 * indistinguishable from a shop whose worker is not running -- and the two
 * need different answers from the merchant. See RunStatus for the `no_data`
 * / not-written-at-all distinction.
 *
 * `salesChannelId` is a plain UUID column, not a DAL association, like
 * StrategyVersion's `strategyId` -- see that entity's docblock for why.
 *
 * Attribute entities carry no schema generator, so this must stay in step
 * with Migration1789900001CreateImprovementRun by hand.
 *
 * No `maxLength:` on the string fields: the argument does not exist at the
 * 6.7.1.0 support floor and a named argument for a parameter the installed
 * core lacks is an Error during the container build. The widths live in the
 * migration. testNoFieldUsesMaxLength pins this for the strategy entities;
 * the same rule applies here even though nothing yet asserts it.
 *
 * @mago-expect lint:too-many-properties
 * The gate fires above 10 and these properties ARE the table's columns, same
 * as QuoteDecisionRecord.
 */
#[Entity('merchant_quote_agent_improvement_run')]
class ImprovementRun extends EntityStruct
{
    #[PrimaryKey]
    #[Field(type: FieldType::UUID, api: ['admin-api' => true, 'store-api' => false])]
    public string $id = '';

    #[Field(type: FieldType::UUID, api: ['admin-api' => true, 'store-api' => false])]
    public ?string $salesChannelId = null;

    #[Field(type: FieldType::DATETIME, api: ['admin-api' => true, 'store-api' => false])]
    public ?\DateTimeImmutable $windowFrom = null;

    #[Field(type: FieldType::DATETIME, api: ['admin-api' => true, 'store-api' => false])]
    public ?\DateTimeImmutable $windowTo = null;

    #[Field(type: FieldType::DATETIME, api: ['admin-api' => true, 'store-api' => false])]
    public ?\DateTimeImmutable $startedAt = null;

    #[Field(type: FieldType::DATETIME, api: ['admin-api' => true, 'store-api' => false])]
    public ?\DateTimeImmutable $finishedAt = null;

    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false])]
    public string $status = RunStatus::Running->value;

    #[Field(type: FieldType::INT, api: ['admin-api' => true, 'store-api' => false])]
    public int $sampled = 0;

    #[Field(type: FieldType::INT, api: ['admin-api' => true, 'store-api' => false])]
    public int $skipped = 0;

    /** @var list<array{pattern: string, count: int, evidence: list<string>}>|null */
    #[Field(type: FieldType::JSON, api: ['admin-api' => true, 'store-api' => false])]
    public ?array $findings = null;

    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false])]
    public ?string $model = null;

    #[Field(type: FieldType::INT, api: ['admin-api' => true, 'store-api' => false])]
    public ?int $promptTokens = null;

    #[Field(type: FieldType::INT, api: ['admin-api' => true, 'store-api' => false])]
    public ?int $completionTokens = null;

    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false])]
    public ?string $error = null;
}
