<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use MerchantQuoteAgentPlugin\Strategy\BuiltInStrategies;
use Override;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * The three strategies the plugin ships, as rows.
 *
 * Idempotent by fixed id: `INSERT IGNORE` on a known primary key, so a rerun
 * or a reinstall changes nothing, and a merchant who archived a built-in does
 * not get it silently resurrected as a new row.
 *
 * Revising a built-in later is a NEW migration appending version 2 to the same
 * lineage -- never an UPDATE here. Old decisions keep resolving to the text
 * that was actually sent, which is the point of versioning them at all.
 */
class Migration1789400001SeedBuiltInStrategies extends MigrationStep
{
    #[Override]
    public function getCreationTimestamp(): int
    {
        return 1789400001;
    }

    /** @throws DbalException */
    #[Override]
    public function update(Connection $connection): void
    {
        $now = (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT);

        foreach (BuiltInStrategies::all() as $id => $definition) {
            $connection->executeStatement('INSERT IGNORE INTO `merchant_quote_agent_strategy`
                    (`id`, `name`, `description`, `created_at`)
                 VALUES (:id, :name, :description, :createdAt)', [
                'id' => hex2bin($id),
                'name' => $definition['name'],
                'description' => $definition['description'],
                'createdAt' => $now,
            ]);

            $connection->executeStatement('INSERT IGNORE INTO `merchant_quote_agent_strategy_version`
                    (`id`, `strategy_id`, `version`, `prompt`, `created_at`)
                 VALUES (:id, :strategyId, 1, :prompt, :createdAt)', [
                // Deterministic id derivation so version 1 is as fixed as
                // the lineage and a rerun cannot insert a second one.
                // Not a security primitive; md5() is used only for determinism.
                'id' => hex2bin(md5($id . ':1')),
                'strategyId' => hex2bin($id),
                'prompt' => $definition['prompt'],
                'createdAt' => $now,
            ]);
        }
    }

    #[Override]
    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive. Removing a built-in would orphan the decisions
        // that recorded one of its versions.
    }
}
