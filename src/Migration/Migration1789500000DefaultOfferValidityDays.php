<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Override;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Moves every shop still holding the old `validityDays` default of `0` onto
 * `14` (#57).
 *
 * Editing config.xml reaches nobody who already has the plugin. Core writes
 * every `defaultValue` into `system_config` at install
 * (`SystemConfigService::saveConfig()`), and on update calls
 * `savePluginConfiguration()` with `$override = false`, which writes a default
 * only `if (!isset($relevantSettings[$key]))` — i.e. never for a key that is
 * already there. Every existing installation therefore holds a literal `0`
 * row that no change to config.xml can touch, and `0` is what made
 * OfferApplier write `+0 days`: an expiry stamped at the instant of the write,
 * with the buyer told the offer was valid until today.
 *
 * Only rows whose stored value reads as zero are rewritten, at every scope. A
 * merchant who typed a number keeps it. `0` is not a setting anyone can have
 * meant — an offer valid for no days cannot be accepted — so this is the
 * plugin correcting a value the plugin itself wrote, not overwriting a
 * choice. The alternative, letting the new `Assert\Positive` reject the stored
 * `0`, would take every installed shop's agent out of service on update over
 * that same value; see the design doc for the argument.
 *
 * A shop cannot arrive back here by clearing the field: that path now fails
 * configuration validation instead, so this migration is a one-off.
 */
class Migration1789500000DefaultOfferValidityDays extends MigrationStep
{
    private const string KEY = 'MerchantQuoteAgentPlugin.config.validityDays';

    private const int DEFAULT_VALIDITY_DAYS = 14;

    #[Override]
    public function getCreationTimestamp(): int
    {
        return 1789500000;
    }

    /**
     * Whether one stored `system_config` value is the broken zero.
     *
     * Static and database-free so the only decision this migration makes can
     * be tested directly, which is also why the `{"_value": ...}` envelope is
     * decoded here rather than matched with `JSON_EXTRACT(...) = 0`:
     * `system:config:set` without `--json` stores the string `"0"` (see
     * docs/end-to-end.md), and SQL's comparison of a JSON string against a
     * number is not something to rely on across MySQL and MariaDB.
     *
     * Anything that does not decode, or decodes to something other than a
     * zero-valued number or numeric string, is left alone.
     */
    public static function isZero(string $configurationValue): bool
    {
        $decoded = json_decode($configurationValue, true);
        $value = \is_array($decoded) ? $decoded['_value'] ?? null : null;

        if (!\is_int($value) && !\is_float($value) && !(\is_string($value) && is_numeric($value))) {
            return false;
        }

        return (float) $value === 0.0;
    }

    /** @throws DbalException|\JsonException */
    #[Override]
    public function update(Connection $connection): void
    {
        /** @var list<array{id: string, configuration_value: string}> $rows */
        $rows = $connection->fetchAllAssociative('SELECT `id`, `configuration_value` FROM `system_config` WHERE `configuration_key` = :key', [
            'key' => self::KEY,
        ]);

        $fixed = json_encode(['_value' => self::DEFAULT_VALIDITY_DAYS], \JSON_THROW_ON_ERROR);

        foreach ($rows as $row) {
            if (!self::isZero($row['configuration_value'])) {
                continue;
            }

            $connection->executeStatement('UPDATE `system_config` SET `configuration_value` = :value WHERE `id` = :id', [
                'value' => $fixed,
                'id' => $row['id'],
            ]);
        }
    }

    #[Override]
    public function updateDestructive(Connection $connection): void
    {
        // Nothing to drop: this migration rewrote one value in place.
    }
}
