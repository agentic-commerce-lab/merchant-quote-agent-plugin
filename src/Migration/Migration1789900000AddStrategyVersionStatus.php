<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Override;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * A proposal is a version row that is not live yet.
 *
 * `version` becomes nullable because a proposed row has no number: numbering
 * it at proposal time would collide with a manual edit made while it waits,
 * and `uniq.mqasv.strategy_version` would then refuse the MERCHANT's own edit.
 * MySQL permits many NULLs under a unique key, so several proposals coexist.
 *
 * Every existing row is `active`, which is the reading that keeps
 * StrategyResolver's behaviour identical on a shop that upgrades.
 */
class Migration1789900000AddStrategyVersionStatus extends MigrationStep
{
    #[Override]
    public function getCreationTimestamp(): int
    {
        return 1789900000;
    }

    /** @throws DbalException */
    #[Override]
    public function update(Connection $connection): void
    {
        $columns = $connection->fetchFirstColumn(
            'SHOW COLUMNS FROM `merchant_quote_agent_strategy_version` LIKE "status"',
        );

        if ($columns !== []) {
            return;
        }

        $connection->executeStatement(<<<'SQL'
                ALTER TABLE `merchant_quote_agent_strategy_version`
                    MODIFY COLUMN `version` INT(11) NULL,
                    ADD COLUMN `status`      VARCHAR(16) NOT NULL DEFAULT 'active',
                    ADD COLUMN `run_id`      BINARY(16)  NULL,
                    ADD COLUMN `evaluation`  JSON        NULL,
                    ADD COLUMN `rationale`   LONGTEXT    NULL,
                    ADD COLUMN `decided_at`  DATETIME(3) NULL,
                    ADD KEY `idx.mqasv.status` (`strategy_id`, `status`);
            SQL);
    }

    #[Override]
    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive. The rows are the merchant's strategy history.
    }
}
