<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Override;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Draft Mode's review lifecycle and the merchant's feedback, on the row of the
 * pass they are about.
 *
 * `draft_version_id`, `review_status` and `review_fingerprint` are written by
 * the drafting pass; the other six by the review endpoints afterwards. All
 * nullable: every existing row, and every autonomous pass, has none of it.
 * `review_status` is indexed because the list page filters and the
 * superseding pass searches on it.
 *
 * Idempotent on `review_status` alone: the nine columns are added in one
 * statement, so either all exist or none do.
 */
class Migration1789800000AddDraftReviewToDecision extends MigrationStep
{
    #[Override]
    public function getCreationTimestamp(): int
    {
        return 1789800000;
    }

    /** @throws DbalException */
    #[Override]
    public function update(Connection $connection): void
    {
        $columns = $connection->fetchFirstColumn('SHOW COLUMNS FROM `merchant_quote_agent_decision` LIKE :column', [
            'column' => 'review_status',
        ]);

        if ($columns !== []) {
            return;
        }

        $connection->executeStatement(<<<'SQL'
                ALTER TABLE `merchant_quote_agent_decision`
                    ADD COLUMN `draft_version_id` BINARY(16) NULL,
                    ADD COLUMN `review_status` VARCHAR(16) NULL,
                    ADD COLUMN `review_fingerprint` LONGTEXT NULL,
                    ADD COLUMN `reviewed_at` DATETIME(3) NULL,
                    ADD COLUMN `sent_reply` LONGTEXT NULL,
                    ADD COLUMN `sent_changes` JSON NULL,
                    ADD COLUMN `feedback_reasons` JSON NULL,
                    ADD COLUMN `feedback_comment` LONGTEXT NULL,
                    ADD COLUMN `feedback_at` DATETIME(3) NULL,
                    ADD KEY `idx.mqad.review_status` (`review_status`);
            SQL);
    }

    #[Override]
    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive. The columns are additive.
    }
}
