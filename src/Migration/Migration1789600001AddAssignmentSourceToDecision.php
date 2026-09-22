<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Override;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Which rung of the assignment ladder chose this pass's strategy.
 *
 * It explains `strategy_version_id` rather than replacing it, and it is the
 * only way a merchant sees that a rule they configured never matched: the
 * ladder falls through silently on purpose, so a row reading `config` where
 * the merchant expected `rule` is the signal.
 *
 * Null on every row written before the ladder existed, and on any pass that
 * ran with no strategy at all. No foreign key and no enum column: the values
 * are StrategyAssignmentSource's four cases, and a widened enum must never be
 * a schema migration on an audit table.
 */
class Migration1789600001AddAssignmentSourceToDecision extends MigrationStep
{
    #[Override]
    public function getCreationTimestamp(): int
    {
        return 1789600001;
    }

    /** @throws DbalException */
    #[Override]
    public function update(Connection $connection): void
    {
        $columns = $connection->fetchFirstColumn('SHOW COLUMNS FROM `merchant_quote_agent_decision` LIKE :column', [
            'column' => 'strategy_assignment_source',
        ]);

        if ($columns !== []) {
            return;
        }

        $connection->executeStatement(<<<'SQL'
                ALTER TABLE `merchant_quote_agent_decision`
                    ADD COLUMN `strategy_assignment_source` VARCHAR(16) NULL;
            SQL);
    }

    #[Override]
    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive. The column is additive.
    }
}
