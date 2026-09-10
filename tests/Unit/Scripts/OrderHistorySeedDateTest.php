<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class OrderHistorySeedDateTest extends TestCase
{
    public function testIncreasingTheTargetPreservesExistingSlotDatesAndAddsDistinctDates(): void
    {
        $four = self::dates(4);
        $eleven = self::dates(11);
        self::assertSame($four, array_slice($eleven, offset: 0, length: 4));
        self::assertCount(11, array_unique($eleven));
        self::assertCount(24, array_unique(self::dates(24)));
    }

    public function testDefaultDatesSpanThePastEighteenMonths(): void
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $expected = array_map(
            static fn(int $days): string => $today->modify('-' . $days . ' days')->format('Y-m-d H:i:s'),
            [517, 345, 173, 1],
        );
        self::assertSame($expected, self::dates(4));
    }

    /** @return list<string> */
    private static function dates(int $target): array
    {
        // Script helpers intentionally are not Composer-autoloaded runtime
        // services. Exercise their standalone PHP loading contract as well.
        $runner = <<<'PHP'
            require $argv[1];
            $dates = [];
            for ($slot = 1; $slot <= (int) $argv[2]; ++$slot) {
                $dates[] = \MerchantQuoteAgentPlugin\Scripts\OrderHistory\SeedData::date($slot, 0)->format('Y-m-d H:i:s');
            }
            fwrite(STDOUT, json_encode($dates, JSON_THROW_ON_ERROR));
            PHP;
        $file = \dirname(__DIR__, levels: 3) . '/scripts/order-history/SeedData.php';
        $output = shell_exec(
            escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($runner) . ' ' . escapeshellarg($file) . ' '
                . escapeshellarg((string) $target),
        );
        self::assertIsString($output);
        $dates = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($dates);
        foreach ($dates as $date) {
            self::assertIsString($date);
        }
        return $dates;
    }
}
