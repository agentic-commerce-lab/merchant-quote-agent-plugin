<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Override;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Our own copy of the A2CN act chain, plus the evidence that is only ours: the
 * protocol violations we observed and the human approval receipts.
 *
 * Hand-written like the decision and pending-authorization tables, and must
 * stay in step with DbalActStore.
 *
 * Ids are stored as the strings the module works in — the session id in its
 * canonical UUIDv5 form, the quote id as Shopware's 32-char hex — because
 * nothing here joins a DAL entity, and the rows are read by a human auditing a
 * session.
 */
class Migration1788600000CreateA2cnEvidence extends MigrationStep
{
    #[Override]
    public function getCreationTimestamp(): int
    {
        return 1788600000;
    }

    /** @throws DbalException */
    #[Override]
    public function update(Connection $connection): void
    {
        // PRIMARY KEY (session_id, sequence) is what makes append() idempotent:
        // re-mirroring an act we already hold must be free, because the emitter
        // mirrors the whole known chain on every observation.
        $connection->executeStatement(<<<'SQL'
                CREATE TABLE IF NOT EXISTS `merchant_quote_agent_a2cn_act` (
                    `session_id` CHAR(36)      NOT NULL,
                    `quote_id`   CHAR(32)      NOT NULL,
                    `sequence`   INT UNSIGNED  NOT NULL,
                    `act`        JSON          NOT NULL,
                    `created_at` DATETIME(3)   NOT NULL,
                    PRIMARY KEY (`session_id`, `sequence`),
                    KEY `idx.a2cn_act.quote_id` (`quote_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);

        $connection->executeStatement(<<<'SQL'
                CREATE TABLE IF NOT EXISTS `merchant_quote_agent_a2cn_violation` (
                    `id`         BINARY(16)  NOT NULL,
                    `session_id` CHAR(36)    NOT NULL,
                    `violation`  JSON        NOT NULL,
                    `created_at` DATETIME(3) NOT NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx.a2cn_violation.session_id` (`session_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);

        $connection->executeStatement(<<<'SQL'
                CREATE TABLE IF NOT EXISTS `merchant_quote_agent_a2cn_receipt` (
                    `session_id` CHAR(36)    NOT NULL,
                    `offer_hash` VARCHAR(64) NOT NULL,
                    `receipt`    JSON        NOT NULL,
                    `created_at` DATETIME(3) NOT NULL,
                    PRIMARY KEY (`session_id`, `offer_hash`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);
    }

    #[Override]
    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive. The tables are dropped on uninstall.
    }
}
