<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Override;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * The assignment ladder's rows: customer pins, rule bindings and split arms,
 * one table discriminated by `kind`.
 *
 * `kind` is explicit rather than derived from which nullable column is set.
 * The derived version reads fine while it is being written and is the thing
 * someone decodes at 3am; the CHECK constraint then makes the two
 * representations unable to disagree.
 *
 * No foreign key on `customer_id`: core's customer rows are deletable, and a
 * pin outliving its account is harmless -- rung 1 simply stops matching. A
 * cascade there would be a silent configuration change on customer deletion.
 *
 * `rule_id` DOES cascade. It is the one dangling reference this plugin does
 * not refuse, because deleting a rule happens in core's rule builder, which
 * offers no hook to refuse from; the choice is a cascade or a row that
 * silently never matches again.
 *
 * The CHECK says nothing about `rule_id`, which is not an oversight: MySQL
 * 8.0.16+ refuses (SQLSTATE 3823) any CHECK naming a column that carries a
 * foreign key referential action, and `rule_id` carries the cascade above. The
 * cascade wins, because losing it means core's rule builder erroring out on a
 * delete the merchant is entitled to make. What the CHECK can no longer forbid
 * is a `kind = 'rule'` row with a NULL `rule_id`; such a row is inert -- the
 * resolver skips rows without a rule id, exactly as it skips a pin whose
 * customer is gone.
 *
 * `sales_channel_id` also cascades: deleting a sales channel should remove the
 * assignment rows scoped to it, because the alternative is configuration that
 * is unreachable in the admin -- there is no channel left to edit it from --
 * but still consulted by the resolver.
 *
 * The unique key does not catch every duplicate pin: MySQL treats NULLs as
 * distinct in a unique index, so two GLOBAL pins for one customer pass. The
 * resolver orders deterministically for that case; see StrategyAssignmentResolver.
 */
class Migration1789600000CreateStrategyAssignment extends MigrationStep
{
    #[Override]
    public function getCreationTimestamp(): int
    {
        return 1789600000;
    }

    /** @throws DbalException */
    #[Override]
    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
                CREATE TABLE IF NOT EXISTS `merchant_quote_agent_strategy_assignment` (
                    `id`               BINARY(16)  NOT NULL,
                    `kind`             VARCHAR(16) NOT NULL,
                    `sales_channel_id` BINARY(16)  NULL,
                    `customer_id`      BINARY(16)  NULL,
                    `rule_id`          BINARY(16)  NULL,
                    `weight`           INT(11)     NULL,
                    `strategy_id`      BINARY(16)  NOT NULL,
                    `created_at`       DATETIME(3) NOT NULL,
                    `updated_at`       DATETIME(3) NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq.mqasa.customer` (`sales_channel_id`, `customer_id`),
                    KEY `idx.mqasa.kind_channel` (`kind`, `sales_channel_id`),
                    KEY `idx.mqasa.strategy_id` (`strategy_id`),
                    CONSTRAINT `fk.mqasa.rule_id` FOREIGN KEY (`rule_id`)
                        REFERENCES `rule` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT `fk.mqasa.sales_channel_id` FOREIGN KEY (`sales_channel_id`)
                        REFERENCES `sales_channel` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT `ck.mqasa.kind` CHECK (
                        (`kind` = 'pin'      AND `customer_id` IS NOT NULL AND `weight` IS NULL)
                     OR (`kind` = 'rule'     AND `customer_id` IS NULL     AND `weight` IS NULL)
                     OR (`kind` = 'split'    AND `customer_id` IS NULL     AND `weight` IS NOT NULL)
                    )
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);
    }

    #[Override]
    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive. The table is the merchant's configuration.
    }
}
