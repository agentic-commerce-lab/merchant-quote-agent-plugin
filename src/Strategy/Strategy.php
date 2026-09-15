<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Strategy;

use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\FieldType;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Entity as EntityStruct;

/**
 * One negotiation strategy: a name, a description, and a lineage of prompt
 * versions held in StrategyVersion.
 *
 * Three rows are seeded and read-only -- the ids in BuiltInStrategies. They
 * are identified by id rather than by a discriminator column, which is core's
 * own idiom for seeded rows (Defaults::LANGUAGE_SYSTEM and friends) and means
 * a merchant naming their own strategy "Fast close" is harmless.
 *
 * Unlike QuoteDecisionRecord this carries NO Protection attribute: the
 * administration writes these rows through the admin API on the merchant's
 * behalf, so system scope would lock out the only thing that writes them.
 * StrategyWriteGuard is what protects the seeded rows and every version row.
 *
 * No `maxLength:` on the string fields: the argument does not exist at the
 * 6.7.1.0 support floor and a named argument for a parameter the installed
 * core lacks is an Error during the container build. The widths live in
 * Migration1789400000CreateQuoteAgentStrategy.
 */
#[Entity('merchant_quote_agent_strategy')]
class Strategy extends EntityStruct
{
    #[PrimaryKey]
    #[Field(type: FieldType::UUID, api: ['admin-api' => true, 'store-api' => false])]
    public string $id = '';

    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false])]
    public string $name = '';

    #[Field(type: FieldType::TEXT, api: ['admin-api' => true, 'store-api' => false])]
    public ?string $description = null;

    /** Archival, not deletion: a decision must keep resolving the version it used. */
    #[Field(type: FieldType::DATETIME, api: ['admin-api' => true, 'store-api' => false])]
    public ?\DateTimeImmutable $archivedAt = null;
}
