<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Strategy;

use MerchantQuoteAgentPlugin\Migration\Migration1789400002MigrateNegotiationStrategyText;
use PHPUnit\Framework\TestCase;

/**
 * The grouping and naming of migrated free text, without a database.
 *
 * The rows arrive as [sales_channel_id (hex or null) => text]; global first,
 * then sales channels by id, which is the order the migration reads them in.
 */
final class StrategyTextMigrationTest extends TestCase
{
    public function testIdenticalTextAcrossChannelsSharesOneStrategy(): void
    {
        $plan = Migration1789400002MigrateNegotiationStrategyText::plan([
            [null,   'open at 2%'],
            ['aaaa', 'open at 2%'],
        ]);

        self::assertCount(1, $plan);
        self::assertSame('open at 2%', $plan[0]['prompt']);
        self::assertSame([null, 'aaaa'], $plan[0]['scopes']);
    }

    public function testDistinctTextsGetDistinctNumberedNames(): void
    {
        $plan = Migration1789400002MigrateNegotiationStrategyText::plan([
            [null,   'open at 2%'],
            ['aaaa', 'hold firm'],
            ['bbbb', 'concede fast'],
        ]);

        self::assertSame(['Custom strategy', 'Custom strategy 2', 'Custom strategy 3'], array_column($plan, 'name'));
    }

    public function testBlankAndWhitespaceOnlyTextIsSkipped(): void
    {
        $plan = Migration1789400002MigrateNegotiationStrategyText::plan([
            [null, ''],
            ['aaaa', '   '],
            ['bbbb', 'hold firm'],
        ]);

        self::assertCount(1, $plan);
        self::assertSame('hold firm', $plan[0]['prompt']);
    }

    public function testNothingToMigrateYieldsAnEmptyPlan(): void
    {
        self::assertSame([], Migration1789400002MigrateNegotiationStrategyText::plan([]));
    }

    public function testTheTimestampIsExact(): void
    {
        // Exact, not merely "greater than the previous migration": a later
        // migration accidentally renumbered into a collision would otherwise
        // pass silently, and Task 9 uses 1789400003.
        self::assertSame(1789400002, (new Migration1789400002MigrateNegotiationStrategyText())->getCreationTimestamp());
    }
}
