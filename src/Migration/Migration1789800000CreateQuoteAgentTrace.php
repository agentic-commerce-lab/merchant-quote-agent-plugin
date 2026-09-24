<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Override;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * The run trace (docs/superpowers/specs/2026-09-23-run-trace-capture-design.md).
 * Hand-written like every table here, and in step with Audit\TraceEvent.
 *
 * No foreign key to the decision table: a trace row outlives a deleted
 * decision (the export skips it), and events outside a pass have no decision
 * at all. Indexed on what the export and the eraser filter by. `occurred_at`
 * is indexed now, for PR 2's event lines, so that PR does not need a second
 * migration.
 */
class Migration1789800000CreateQuoteAgentTrace extends MigrationStep
{
    #[Override]
    public function getCreationTimestamp(): int
    {
        return 1789800000;
    }

    /** @throws DbalException */
    #[Override]
    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
                CREATE TABLE IF NOT EXISTS `merchant_quote_agent_trace` (
                    `id`           BINARY(16)  NOT NULL,
                    `decision_id`  BINARY(16)  NULL,
                    `quote_id`     BINARY(16)  NULL,
                    `customer_id`  BINARY(16)  NULL,
                    `kind`         VARCHAR(32) NOT NULL,
                    `position`     INT(11)     NULL,
                    `occurred_at`  DATETIME(3) NOT NULL,
                    `meta`         JSON        NULL,
                    `content`      JSON        NULL,
                    `created_at`   DATETIME(3) NOT NULL,
                    `updated_at`   DATETIME(3) NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx.mqat.decision_id` (`decision_id`),
                    KEY `idx.mqat.quote_id` (`quote_id`),
                    KEY `idx.mqat.customer_id` (`customer_id`),
                    KEY `idx.mqat.occurred_at` (`occurred_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);
    }

    #[Override]
    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive. The table is additive and the merchant's data.
    }
}
