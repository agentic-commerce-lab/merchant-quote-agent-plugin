<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Override;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * When a human resolved an escalation, and what they moved the quote to.
 *
 * The third and fourth columns on this table not written by a servicing pass,
 * joining `terminal_state` / `terminal_at`: EscalationResolutionSubscriber
 * stamps them later, so a record's insert still has exactly one owner.
 *
 * SwagCommercial 7.13 has a `quote_history` table that would have made this
 * measurable retroactively with no new storage. 7.12 does not, and the plugin
 * supports both, so the plugin records it itself.
 */
class Migration1789000000AddEscalationResolution extends MigrationStep
{
    #[Override]
    public function getCreationTimestamp(): int
    {
        return 1789000000;
    }

    /** @throws DbalException */
    #[Override]
    public function update(Connection $connection): void
    {
        $columns = $connection->fetchFirstColumn('SHOW COLUMNS FROM `merchant_quote_agent_decision` LIKE "resolved_%"');

        if ($columns !== []) {
            return;
        }

        $connection->executeStatement(<<<'SQL'
                ALTER TABLE `merchant_quote_agent_decision`
                    ADD COLUMN `resolved_at`    DATETIME(3) NULL,
                    ADD COLUMN `resolved_state` VARCHAR(64) NULL;
            SQL);
    }

    #[Override]
    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive. The columns are additive and the merchant's data.
    }
}
