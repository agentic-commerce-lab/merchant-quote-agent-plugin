<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Override;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * The plugin's first migration. Attribute entities carry no schema generator,
 * so the table is hand-written and must stay in step with QuoteDecisionRecord.
 *
 * Indexed on what #21 and #7 actually query: the quote, the outcome, the
 * channel, and time.
 */
class Migration1787998662CreateQuoteAgentDecision extends MigrationStep
{
    #[Override]
    public function getCreationTimestamp(): int
    {
        return 1787998662;
    }

    /** @throws DbalException */
    #[Override]
    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
                CREATE TABLE IF NOT EXISTS `merchant_quote_agent_decision` (
                    `id`                        BINARY(16)   NOT NULL,
                    `quote_id`                  BINARY(16)   NOT NULL,
                    `quote_number`              VARCHAR(64)  NULL,
                    `sales_channel_id`          BINARY(16)   NULL,
                    `currency_iso`              VARCHAR(3)   NULL,
                    `trigger_reason`            VARCHAR(64)  NULL,
                    `attempt`                   INT(11)      NULL,
                    `revision_version_id`       VARCHAR(64)  NULL,
                    `revision_updated_at`       DATETIME(3)  NULL,
                    `band`                      VARCHAR(32)  NULL,
                    `outcome`                   VARCHAR(32)  NULL,
                    `escalation_reason`         VARCHAR(64)  NULL,
                    `discount_percent_granted`  DOUBLE       NULL,
                    `max_discount_percent`      DOUBLE       NULL,
                    `total_net_before`          DOUBLE       NULL,
                    `total_net_after`           DOUBLE       NULL,
                    `model`                     VARCHAR(128) NULL,
                    `model_host`                VARCHAR(255) NULL,
                    `extract_prompt_hash`       VARCHAR(64)  NULL,
                    `negotiate_prompt_hash`     VARCHAR(64)  NULL,
                    `reply_prompt_hash`         VARCHAR(64)  NULL,
                    `prompt_tokens`             INT(11)      NULL,
                    `completion_tokens`         INT(11)      NULL,
                    `model_latency_ms`          INT(11)      NULL,
                    `duration_ms`               INT(11)      NULL,
                    `authorized`                TINYINT(1)   NULL,
                    `verified`                  TINYINT(1)   NULL,
                    `error_class`               VARCHAR(255) NULL,
                    `terminal_state`            VARCHAR(64)  NULL,
                    `terminal_at`               DATETIME(3)  NULL,
                    `interpreted_asks`          JSON         NULL,
                    `raw_proposal`              LONGTEXT     NULL,
                    `violations`                JSON         NULL,
                    `writes`                    JSON         NULL,
                    `error_chain`               JSON         NULL,
                    `buyer_comment`             LONGTEXT     NULL,
                    `created_at`                DATETIME(3)  NOT NULL,
                    `updated_at`                DATETIME(3)  NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx.mqad.quote_id` (`quote_id`),
                    KEY `idx.mqad.created_at` (`created_at`),
                    KEY `idx.mqad.outcome` (`outcome`),
                    KEY `idx.mqad.sales_channel_id` (`sales_channel_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);
    }

    #[Override]
    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive. The table is additive and the merchant's data.
    }
}
