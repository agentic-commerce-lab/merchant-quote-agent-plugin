<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Strategy;

use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\FieldType;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Entity as EntityStruct;

/**
 * One rung's row: a customer pin, a rule binding, or a split arm.
 *
 * `kind` holds a StrategyAssignmentSource VALUE -- `pin`, `rule` or `split`,
 * never `config`, which is the absence of a row rather than a row. The
 * resolver writes and reads it as `StrategyAssignmentSource::Pin->value` and
 * friends, never as a literal, so the column and the audit column it explains
 * cannot drift apart.
 *
 * `ruleId`, `customerId` and `strategyId` are plain UUID columns, not DAL
 * associations, for the reason StrategyVersion gives: association attributes
 * sit outside what CoreFloorCompatibilityTest checks, and that test is skipped
 * entirely without a local core clone, so CI would not catch a floor breakage
 * there. The database-level foreign keys in the migration are what enforce
 * referential integrity.
 *
 * No `maxLength:` on `kind` -- the argument does not exist at the 6.7.1.0
 * support floor. The width lives in the migration.
 *
 * Like Strategy and unlike QuoteDecisionRecord, this carries no Protection
 * attribute: the administration writes these rows through the admin API on the
 * merchant's behalf, so system scope would lock out the only thing that writes
 * them.
 */
#[Entity('merchant_quote_agent_strategy_assignment')]
class StrategyAssignment extends EntityStruct
{
    #[PrimaryKey]
    #[Field(type: FieldType::UUID, api: ['admin-api' => true, 'store-api' => false])]
    public string $id = '';

    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false])]
    public string $kind = '';

    #[Field(type: FieldType::UUID, api: ['admin-api' => true, 'store-api' => false])]
    public ?string $salesChannelId = null;

    #[Field(type: FieldType::UUID, api: ['admin-api' => true, 'store-api' => false])]
    public ?string $customerId = null;

    #[Field(type: FieldType::UUID, api: ['admin-api' => true, 'store-api' => false])]
    public ?string $ruleId = null;

    #[Field(type: FieldType::INT, api: ['admin-api' => true, 'store-api' => false])]
    public ?int $weight = null;

    #[Field(type: FieldType::UUID, api: ['admin-api' => true, 'store-api' => false])]
    public string $strategyId = '';
}
