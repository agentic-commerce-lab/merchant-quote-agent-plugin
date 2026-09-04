<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Override;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * `buyer_comment` never held a buyer comment.
 *
 * DecisionRecorder::recordReply() is its only writer, so the column has always
 * held the agent's message TO the buyer. Under the old name the admin rendered
 * it as the buyer speaking, which described conversations that never happened.
 * The buyer's own words are not in this table at all — their ask survives only
 * in structured form, in `interpreted_asks`.
 *
 * The create migration is left as it shipped: an install that already ran it
 * has the row in the migration table and would not re-run an edited copy, so
 * editing it would only make fresh installs diverge from existing ones. Both
 * therefore run create-then-rename, and the guard below makes that idempotent.
 */
class Migration1788525369RenameBuyerCommentToReplyToBuyer extends MigrationStep
{
    #[Override]
    public function getCreationTimestamp(): int
    {
        return 1788525369;
    }

    /** @throws DbalException */
    #[Override]
    public function update(Connection $connection): void
    {
        if (!$this->hasColumn($connection, 'buyer_comment')) {
            return;
        }

        // CHANGE rather than RENAME COLUMN: RENAME needs MySQL 8.0 / MariaDB
        // 10.5, and Shopware still supports platforms below both.
        $connection->executeStatement('ALTER TABLE `merchant_quote_agent_decision`
                CHANGE `buyer_comment` `reply_to_buyer` LONGTEXT NULL');
    }

    #[Override]
    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive. The rename carries the data across.
    }

    /** @throws DbalException */
    private function hasColumn(Connection $connection, string $column): bool
    {
        $found = $connection->fetchOne('SHOW COLUMNS FROM `merchant_quote_agent_decision` LIKE :column', [
            'column' => $column,
        ]);

        return false !== $found;
    }
}
