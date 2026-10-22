<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Override;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Which strategy a run row is about.
 *
 * The nightly loop now groups a window's decisions by strategy lineage before
 * evaluating them (see DecisionHarvest, StrategyGroup), so a tick that sees
 * decisions from two strategies writes two run rows, not one -- a merchant
 * comparing arms needs to tell them apart. Null only on the `no_data` row an
 * empty or fully-unattributed window writes, before grouping has anything to
 * name (see ImprovementRunWriter::writeNoData()).
 *
 * No foreign key, same as `sales_channel_id` on this table and `strategy_id`
 * on `merchant_quote_agent_strategy_version`: the DAL side declares no
 * association either (see StrategyVersion's own docblock for why).
 */
class Migration1789900002AddStrategyToImprovementRun extends MigrationStep
{
    #[Override]
    public function getCreationTimestamp(): int
    {
        return 1789900002;
    }

    /** @throws DbalException */
    #[Override]
    public function update(Connection $connection): void
    {
        $columns = $connection->fetchFirstColumn('SHOW COLUMNS FROM `merchant_quote_agent_improvement_run` LIKE :column', [
            'column' => 'strategy_id',
        ]);

        if ($columns !== []) {
            return;
        }

        $connection->executeStatement(<<<'SQL'
                ALTER TABLE `merchant_quote_agent_improvement_run`
                    ADD COLUMN `strategy_id` BINARY(16) NULL AFTER `sales_channel_id`,
                    ADD KEY `idx.mqair.strategy_started` (`strategy_id`, `started_at`);
            SQL);
    }

    #[Override]
    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive. The column is additive.
    }
}
