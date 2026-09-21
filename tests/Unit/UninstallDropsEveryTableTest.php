<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit;

use MerchantQuoteAgentPlugin\MerchantQuoteAgentPlugin;
use PHPUnit\Framework\TestCase;

/**
 * Uninstall-with-data-removal must drop every table the plugin creates.
 *
 * This is the guard #59 lacked. That issue was not a tidiness complaint: the
 * decision table holds `buyer_comment`, `reply_to_buyer`, `interpreted_asks`
 * and `customer_id`, so a merchant who ticks "remove all data permanently" and
 * silently keeps it has been told something untrue about data they still hold.
 *
 * Nothing else would catch a recurrence. The drop list is a hand-written array
 * and the tables are hand-written `CREATE TABLE`s in migrations; the two drift
 * the moment someone adds a migration and forgets, and the symptom only ever
 * appears on a real shop, after an uninstall, where nobody is looking.
 *
 * So this reads both sides from source and compares them. Adding a migration
 * that creates a table now fails here until the table is also dropped.
 */
final class UninstallDropsEveryTableTest extends TestCase
{
    public function testEveryTableAMigrationCreatesIsAlsoDropped(): void
    {
        $missing = array_diff($this->tablesCreatedByMigrations(), $this->tablesDroppedOnUninstall());

        self::assertSame(
            [],
            array_values($missing),
            'A migration creates these tables but uninstall never drops them, so they survive a '
                . "merchant's \"remove all data\" and #59 recurs:\n  "
                . implode("\n  ", $missing),
        );
    }

    public function testEveryDroppedTableIsOneAMigrationActuallyCreates(): void
    {
        $unknown = array_diff($this->tablesDroppedOnUninstall(), $this->tablesCreatedByMigrations());

        self::assertSame(
            [],
            array_values($unknown),
            'Uninstall drops these tables, but no migration creates them — a rename or a stale ' . "entry:\n  "
                . implode("\n  ", $unknown),
        );
    }

    /**
     * Read from the migrations rather than from a second hand-written list,
     * which would be one more copy to drift.
     *
     * @return list<string>
     */
    private function tablesCreatedByMigrations(): array
    {
        $tables = [];

        foreach (glob(__DIR__ . '/../../src/Migration/*.php') ?: [] as $file) {
            $source = file_get_contents($file);
            self::assertIsString($source, 'Could not read ' . $file);

            preg_match_all('/CREATE TABLE IF NOT EXISTS `([a-z0-9_]+)`/i', $source, $matches);
            $tables = [...$tables, ...$matches[1]];
        }

        self::assertNotEmpty($tables, 'Found no CREATE TABLE in src/Migration — has the idiom changed?');

        return array_values(array_unique($tables));
    }

    /**
     * The literal array inside `dropPluginTables()`, read from source because
     * the method is private and needs a container to run.
     *
     * @return list<string>
     */
    private function tablesDroppedOnUninstall(): array
    {
        $file = (new \ReflectionClass(MerchantQuoteAgentPlugin::class))->getFileName();
        self::assertIsString($file);

        $source = file_get_contents($file);
        self::assertIsString($source);

        $body = preg_split('/private function dropPluginTables\(/', $source);
        self::assertIsArray($body);
        self::assertCount(2, $body, 'dropPluginTables() not found — was it renamed?');

        preg_match_all("/'(merchant_quote_agent_[a-z0-9_]+)'/", $body[1], $matches);
        self::assertNotEmpty($matches[1], 'dropPluginTables() lists no tables.');

        return array_values(array_unique($matches[1]));
    }
}
