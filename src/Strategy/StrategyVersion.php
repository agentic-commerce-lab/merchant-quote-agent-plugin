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
 * That immutability is the whole audit guarantee: a decision row stores a
 * version id, so "what exactly did we tell the model on that quote" stays
 * answerable however often the strategy is edited afterwards. It is enforced
 * in StrategyWriteGuard, not merely by convention.
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

    #[Field(type: FieldType::INT, api: ['admin-api' => true, 'store-api' => false])]
    public int $version = 1;

    #[Field(type: FieldType::TEXT, api: ['admin-api' => true, 'store-api' => false])]
    public string $prompt = '';
}
