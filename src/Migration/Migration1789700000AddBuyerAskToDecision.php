<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Override;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * The buyer's own words, which this table has never held.
 *
 * `buyer_comment` held the agent's reply until it was renamed to
 * `reply_to_buyer` (Migration1788525369); the buyer's ask survived only in
 * structured form, in `interpreted_asks`. That was tolerable while every pass
 * that read a comment also answered it.
 *
 * #177 changed that: an extraction empty in every field now ends the pass as
 * `NothingToDo` and the servicing fingerprint is stamped anyway, so a question
 * the model mis-reads as empty is answered with silence. The trade-off was
 * accepted on the grounds that a merchant can review those rows and see what
 * the agent decided not to answer — which needs the comment to be on the row,
 * and `interpreted_asks` is null on exactly those rows.
 *
 * Null on every row written before this column existed, and on any pass that
 * read no comment at all. LONGTEXT to match `reply_to_buyer`: a quote comment
 * has no length limit worth guessing at, and a truncated audit record of what
 * the buyer asked is the failure this column exists to prevent.
 */
class Migration1789700000AddBuyerAskToDecision extends MigrationStep
{
    #[Override]
    public function getCreationTimestamp(): int
    {
        return 1789700000;
    }

    /** @throws DbalException */
    #[Override]
    public function update(Connection $connection): void
    {
        $columns = $connection->fetchFirstColumn('SHOW COLUMNS FROM `merchant_quote_agent_decision` LIKE :column', [
            'column' => 'buyer_ask',
        ]);

        if ($columns !== []) {
            return;
        }

        $connection->executeStatement(<<<'SQL'
                ALTER TABLE `merchant_quote_agent_decision`
                    ADD COLUMN `buyer_ask` LONGTEXT NULL;
            SQL);
    }

    #[Override]
    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive. The column is additive.
    }
}
