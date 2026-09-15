<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The `system_config` rows Migration1789400002MigrateNegotiationStrategyText
 * reads and writes.
 *
 * Split out from the migration itself only to stay under the project's
 * per-class cyclomatic-complexity gate; it has no reason to exist on its own
 * otherwise, and nothing outside that one migration should depend on it.
 */
final class NegotiationStrategyTextConfigRows
{
    private const OLD_KEY = 'MerchantQuoteAgentPlugin.config.negotiationStrategy';

    private const NEW_KEY = 'MerchantQuoteAgentPlugin.config.negotiationStrategyId';

    /**
     * Global scope first, then sales channels by id, so the numbering in
     * Migration1789400002MigrateNegotiationStrategyText::plan() is stable
     * across reruns and across shops.
     *
     * @throws DbalException
     *
     * @return list<array{0: ?string, 1: string}>
     */
    public function existingText(Connection $connection): array
    {
        /** @var list<array{sales_channel_id: ?string, configuration_value: string}> $rows */
        $rows = $connection->fetchAllAssociative(
            'SELECT LOWER(HEX(`sales_channel_id`)) AS `sales_channel_id`, `configuration_value`
             FROM `system_config`
             WHERE `configuration_key` = :key
             ORDER BY `sales_channel_id` IS NOT NULL, `sales_channel_id`',
            ['key' => self::OLD_KEY],
        );

        $pairs = [];

        foreach ($rows as $row) {
            $decoded = json_decode($row['configuration_value'], true);
            $value = \is_array($decoded) ? $decoded['_value'] ?? null : null;

            if (!\is_string($value)) {
                continue;
            }

            $pairs[] = [$row['sales_channel_id'], $value];
        }

        return $pairs;
    }

    /**
     * Inserts the new `negotiationStrategyId` row for one scope, unless one is
     * already there.
     *
     * Defence in depth: Shopware's migration table normally prevents a
     * re-run, but a migration that throws on a duplicate key blocks the
     * merchant's entire plugin update, not just this feature. `system_config`
     * does carry a unique key on (`configuration_key`, `sales_channel_id`),
     * but MySQL treats every NULL as distinct for uniqueness purposes, so it
     * would not stop a duplicate at the global scope -- this explicit check
     * is what actually does.
     *
     * @throws DbalException
     */
    public function insertStrategyId(
        Connection $connection,
        ?string $salesChannelIdHex,
        string $strategyId,
        string $now,
    ): void {
        // hex2bin() is typed as returning `string|false`, but the hex here
        // always comes from our own LOWER(HEX(...)) read, so it is always
        // valid.
        /** @var ?string $salesChannelId */
        $salesChannelId = $salesChannelIdHex === null ? null : hex2bin($salesChannelIdHex);

        if ($this->configRowExists($connection, $salesChannelId)) {
            return;
        }

        $connection->executeStatement('INSERT INTO `system_config`
                (`id`, `configuration_key`, `configuration_value`, `sales_channel_id`, `created_at`)
             VALUES (:id, :key, :value, :salesChannelId, :createdAt)', [
            'id' => Uuid::randomBytes(),
            'key' => self::NEW_KEY,
            // system_config stores a JSON object with a `_value` key.
            'value' => json_encode(['_value' => bin2hex($strategyId)], \JSON_THROW_ON_ERROR),
            'salesChannelId' => $salesChannelId,
            'createdAt' => $now,
        ]);
    }

    /**
     * `<=>` is MySQL's null-safe equality operator: unlike `=`, it matches
     * NULL to NULL, so the global scope (`sales_channel_id IS NULL`) is
     * checked correctly in the same query as every sales channel, with no
     * separate branch for it.
     *
     * @throws DbalException
     */
    private function configRowExists(Connection $connection, ?string $salesChannelId): bool
    {
        return $connection->fetchOne('SELECT 1 FROM `system_config`
             WHERE `configuration_key` = :key AND `sales_channel_id` <=> :salesChannelId
             LIMIT 1', [
            'key' => self::NEW_KEY,
            'salesChannelId' => $salesChannelId,
        ]) !== false;
    }
}
