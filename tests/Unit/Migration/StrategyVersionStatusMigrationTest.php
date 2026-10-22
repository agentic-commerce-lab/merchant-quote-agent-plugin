<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Migration;

use MerchantQuoteAgentPlugin\Migration\Migration1789900000AddStrategyVersionStatus;
use PHPUnit\Framework\TestCase;

/**
 * No database in this suite, so this pins the migration's source: it must
 * run after the strategy tables and must not drop anything — the rows are
 * the merchant's strategy history.
 */
final class StrategyVersionStatusMigrationTest extends TestCase
{
    private static function source(): string
    {
        $file = (new \ReflectionClass(Migration1789900000AddStrategyVersionStatus::class))->getFileName();
        self::assertIsString($file);

        $source = file_get_contents($file);
        self::assertIsString($source);

        return $source;
    }

    public function testItIsTimestampedAfterTheStrategyTables(): void
    {
        self::assertGreaterThan(
            1789400000,
            (new Migration1789900000AddStrategyVersionStatus())->getCreationTimestamp(),
        );
    }

    public function testItDropsNothing(): void
    {
        self::assertStringNotContainsString('DROP', self::source());
    }
}
