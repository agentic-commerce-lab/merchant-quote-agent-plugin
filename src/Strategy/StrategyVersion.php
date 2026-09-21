<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Strategy;

use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\FieldType;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Entity as EntityStruct;

/**
 * One immutable prompt for one strategy. Editing a strategy appends a version;
 * nothing ever rewrites one.
 *
 * A version row's prompt never changes, and only a `proposed` row's status
 * may change. That is the whole audit guarantee: a decision row stores a
 * version id, so "what exactly did we tell the model on that quote" stays
 * answerable however often the strategy is edited afterwards, and however a
 * proposal is accepted or rejected. It is enforced in StrategyWriteGuard, not
 * merely by convention.
 *
 * `strategyId` is a plain UUID column, not a DAL association. See the design
 * doc: the association attributes are outside what CoreFloorCompatibilityTest
 * can check, and that test is skipped without a local core clone.
 */
#[Entity('merchant_quote_agent_strategy_version')]
class StrategyVersion extends EntityStruct
{
    #[PrimaryKey]
    #[Field(type: FieldType::UUID, api: ['admin-api' => true, 'store-api' => false])]
    public string $id = '';

    #[Field(type: FieldType::UUID, api: ['admin-api' => true, 'store-api' => false])]
    public string $strategyId = '';

    /** Null while proposed: the number is assigned when a human accepts it. */
    #[Field(type: FieldType::INT, api: ['admin-api' => true, 'store-api' => false])]
    public ?int $version = null;

    #[Field(type: FieldType::TEXT, api: ['admin-api' => true, 'store-api' => false])]
    public string $prompt = '';

    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false])]
    public string $status = VersionStatus::Active->value;

    /** The improvement run that proposed this row; null for every human-written version. */
    #[Field(type: FieldType::UUID, api: ['admin-api' => true, 'store-api' => false])]
    public ?string $runId = null;

    /** @var array<string, mixed>|null The A/B replay result behind a proposal. */
    #[Field(type: FieldType::JSON, api: ['admin-api' => true, 'store-api' => false])]
    public ?array $evaluation = null;

    #[Field(type: FieldType::TEXT, api: ['admin-api' => true, 'store-api' => false])]
    public ?string $rationale = null;

    #[Field(type: FieldType::DATETIME, api: ['admin-api' => true, 'store-api' => false])]
    public ?\DateTimeImmutable $decidedAt = null;
}
