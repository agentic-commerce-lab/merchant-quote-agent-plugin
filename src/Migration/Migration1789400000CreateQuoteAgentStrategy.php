<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Override;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * The strategy library. Two tables: a lineage, and its immutable prompt
 * versions.
 *
 * Attribute entities carry no schema generator, so these are hand-written and
 * must stay in step with Strategy and StrategyVersion.
 *
 * No foreign key from version to strategy. The DAL side deliberately declares
 * no association either -- see the design doc -- and a merchant's library is
 * small enough that the unique key below is the constraint that matters.
 */
class Migration1789400000CreateQuoteAgentStrategy extends MigrationStep
{
    #[Override]
    public function getCreationTimestamp(): int
    {
        return 1789400000;
    }

    /** @throws DbalException */
    #[Override]
    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
                CREATE TABLE IF NOT EXISTS `merchant_quote_agent_strategy` (
                    `id`          BINARY(16)   NOT NULL,
                    `name`        VARCHAR(255) NOT NULL,
                    `description` LONGTEXT     NULL,
                    `archived_at` DATETIME(3)  NULL,
                    `created_at`  DATETIME(3)  NOT NULL,
                    `updated_at`  DATETIME(3)  NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx.mqas.archived_at` (`archived_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);

        $connection->executeStatement(<<<'SQL'
                CREATE TABLE IF NOT EXISTS `merchant_quote_agent_strategy_version` (
                    `id`          BINARY(16)  NOT NULL,
                    `strategy_id` BINARY(16)  NOT NULL,
                    `version`     INT(11)     NOT NULL,
                    `prompt`      LONGTEXT    NOT NULL,
                    `created_at`  DATETIME(3) NOT NULL,
                    `updated_at`  DATETIME(3) NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq.mqasv.strategy_version` (`strategy_id`, `version`),
                    KEY `idx.mqasv.strategy_id` (`strategy_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);
    }

    #[Override]
    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive. The tables are the merchant's data.
    }
}
