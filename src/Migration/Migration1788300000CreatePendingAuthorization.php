<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Override;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Authorization requests awaiting a human answer. Hand-written like the
 * decision table, and must stay in step with DbalPendingAuthorizationStore.
 *
 * The primary key is the handle's sha256, so the plaintext handle exists only
 * in the agent's memory and in the URL the human opens.
 */
class Migration1788300000CreatePendingAuthorization extends MigrationStep
{
    #[Override]
    public function getCreationTimestamp(): int
    {
        return 1788300000;
    }

    /** @throws DbalException */
    #[Override]
    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
                CREATE TABLE IF NOT EXISTS `merchant_quote_agent_pending_authorization` (
                    `handle_hash`           BINARY(32)   NOT NULL,
                    `sales_channel_id`      BINARY(16)   NOT NULL,
                    `client_id`             VARCHAR(2048) NOT NULL,
                    `agent_profile`         JSON         NOT NULL,
                    `redirect_uri`          VARCHAR(2048) NOT NULL,
                    `scope`                 VARCHAR(1024) NOT NULL,
                    `state`                 VARCHAR(255) NOT NULL,
                    `code_challenge`        VARCHAR(255) NOT NULL,
                    `code_challenge_method` VARCHAR(16)  NOT NULL,
                    `created_at`            DATETIME(3)  NOT NULL,
                    `expires_at`            DATETIME(3)  NOT NULL,
                    `consumed_at`           DATETIME(3)  NULL,
                    PRIMARY KEY (`handle_hash`),
                    KEY `idx.pending_authorization.expires_at` (`expires_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);
    }

    #[Override]
    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive. The table is dropped with the plugin.
    }
}
