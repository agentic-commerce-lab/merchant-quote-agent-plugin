<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Override;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * The gross totals, so an export can be read alone.
 *
 * `reply_to_buyer` states the gross total (ReplyComposer quotes
 * `buyerFacingTotal()`), and the row held only net: read side by side, every
 * taxed reply looked like a wrong number. `total_gross_*` come from the same
 * two snapshots as `total_net_*`.
 *
 * Null on every row written before these columns existed. One ALTER, guarded
 * on the first column: the two are only ever added together.
 */
class Migration1789900000AddGrossTotalsToDecision extends MigrationStep
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
        $columns = $connection->fetchFirstColumn('SHOW COLUMNS FROM `merchant_quote_agent_decision` LIKE :column', [
            'column' => 'total_gross_before',
        ]);

        if ($columns !== []) {
            return;
        }

        $connection->executeStatement(<<<'SQL'
                ALTER TABLE `merchant_quote_agent_decision`
                    ADD COLUMN `total_gross_before` DOUBLE NULL,
                    ADD COLUMN `total_gross_after`  DOUBLE NULL;
            SQL);
    }

    #[Override]
    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive. The columns are additive.
    }
}
