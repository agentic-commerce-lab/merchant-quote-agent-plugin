<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Override;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1789000000AddCustomerHistoryToDecision extends MigrationStep
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
        if (!$this->hasColumn($connection, 'customer_id')) {
            // Keep the column and its lookup index in one ALTER so a failed
            // index creation cannot leave an unindexed customer column.
            $connection->executeStatement('ALTER TABLE `merchant_quote_agent_decision`
                ADD COLUMN `customer_id` BINARY(16) NULL,
                ADD INDEX `idx.mqad.customer_id` (`customer_id`)');
        } elseif (
            false === $connection->fetchOne('SHOW INDEX FROM `merchant_quote_agent_decision` WHERE Key_name = :index', [
                'index' => 'idx.mqad.customer_id',
            ])
        ) {
            // Recover an existing column from a partial/manual installation.
            $connection->executeStatement('ALTER TABLE `merchant_quote_agent_decision`
                ADD INDEX `idx.mqad.customer_id` (`customer_id`)');
        }

        if (!$this->hasColumn($connection, 'history_reads')) {
            $connection->executeStatement('ALTER TABLE `merchant_quote_agent_decision`
                ADD COLUMN `history_reads` JSON NULL');
        }
    }

    #[Override]
    public function updateDestructive(Connection $connection): void
    {
        // No destructive changes; earlier decisions retain nullable history.
    }

    /** @throws DbalException */
    private function hasColumn(Connection $connection, string $column): bool
    {
        return false !== $connection->fetchOne('SHOW COLUMNS FROM `merchant_quote_agent_decision` LIKE :column', [
            'column' => $column,
        ]);
    }
}
