<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Override;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * One row per nightly self-improvement run, per sales channel.
 *
 * Attribute entities carry no schema generator, so this is hand-written and
 * must stay in step with ImprovementRun.
 *
 * No foreign key to sales_channel or to the strategy version a run may later
 * propose -- ImprovementRun predates any proposal, and the DAL side
 * deliberately declares no association either (see StrategyVersion's
 * docblock for why).
 */
class Migration1789900001CreateImprovementRun extends MigrationStep
{
    #[Override]
    public function getCreationTimestamp(): int
    {
        return 1789900001;
    }

    /** @throws DbalException */
    #[Override]
    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
                CREATE TABLE IF NOT EXISTS `merchant_quote_agent_improvement_run` (
                    `id`                 BINARY(16)   NOT NULL,
                    `sales_channel_id`   BINARY(16)   NULL,
                    `window_from`        DATETIME(3)  NULL,
                    `window_to`          DATETIME(3)  NULL,
                    `started_at`         DATETIME(3)  NULL,
                    `finished_at`        DATETIME(3)  NULL,
                    `status`             VARCHAR(32)  NOT NULL,
                    `sampled`            INT(11)      NOT NULL DEFAULT 0,
                    `skipped`            INT(11)      NOT NULL DEFAULT 0,
                    `findings`           JSON         NULL,
                    `model`              VARCHAR(128) NULL,
                    `prompt_tokens`      INT(11)      NULL,
                    `completion_tokens`  INT(11)      NULL,
                    `error`              VARCHAR(255) NULL,
                    `created_at`         DATETIME(3)  NOT NULL,
                    `updated_at`         DATETIME(3)  NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx.mqair.channel_started` (`sales_channel_id`, `started_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);
    }

    #[Override]
    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive. The table is the merchant's run history.
    }
}
