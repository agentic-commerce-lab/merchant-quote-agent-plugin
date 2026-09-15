<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Override;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Which prompt this pass actually sent.
 *
 * No foreign key, like every other id on this table: an audit row has to keep
 * resolving after anything it points at is archived. Null on every row written
 * before the library existed, and on any pass that ran with no strategy set.
 */
class Migration1789400003AddStrategyVersionToDecision extends MigrationStep
{
    #[Override]
    public function getCreationTimestamp(): int
    {
        return 1789400003;
    }

    /** @throws DbalException */
    #[Override]
    public function update(Connection $connection): void
    {
        $columns = $connection->fetchFirstColumn('SHOW COLUMNS FROM `merchant_quote_agent_decision` LIKE :column', [
            'column' => 'strategy_version_id',
        ]);

        if ($columns !== []) {
            return;
        }

        $connection->executeStatement(<<<'SQL'
                ALTER TABLE `merchant_quote_agent_decision`
                    ADD COLUMN `strategy_version_id` BINARY(16) NULL,
                    ADD KEY `idx.mqad.strategy_version_id` (`strategy_version_id`);
            SQL);
    }

    #[Override]
    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive. The column is additive.
    }
}
