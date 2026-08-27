<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1787849556CreateServicingAttempt extends MigrationStep
{
    #[\Override]
    public function getCreationTimestamp(): int
    {
        return 1_787_849_556;
    }

    /** @throws \Doctrine\DBAL\Exception */
    #[\Override]
    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `merchant_quote_agent_servicing_attempt` (
                `id` BINARY(16) NOT NULL,
                `attempt_count` INT UNSIGNED NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }

    #[\Override]
    public function updateDestructive(Connection $connection): void {}
}
