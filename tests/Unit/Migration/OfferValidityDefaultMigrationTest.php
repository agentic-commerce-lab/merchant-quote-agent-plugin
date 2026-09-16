<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Migration;

use MerchantQuoteAgentPlugin\Migration\Migration1789500000DefaultOfferValidityDays;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which stored `system_config` values the #57 migration may rewrite, without
 * a database.
 *
 * The envelope is decoded in PHP rather than matched in SQL because
 * `system:config:set` without `--json` stores the *string* "0" — the trap
 * docs/end-to-end.md warns about — and that row is as broken as the numeric
 * one. A value that does not decode is left alone: one we cannot read is not
 * one we may overwrite.
 */
final class OfferValidityDefaultMigrationTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: bool}> */
    public static function storedValues(): iterable
    {
        yield 'the shipped default' => ['{"_value":0}', true];
        yield 'a float zero' => ['{"_value":0.0}', true];
        yield 'set from the CLI without --json' => ['{"_value":"0"}', true];
        yield 'a validity the merchant set' => ['{"_value":14}', false];
        yield 'a validity of one' => ['{"_value":1}', false];
        yield 'an explicitly null value' => ['{"_value":null}', false];
        yield 'a non-numeric string' => ['{"_value":"none"}', false];
        yield 'no envelope' => ['0', false];
        yield 'undecodable junk' => ['{"_value":', false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('storedValues')]
    public function testOnlyAStoredZeroIsRewritten(string $stored, bool $expected): void
    {
        self::assertSame($expected, Migration1789500000DefaultOfferValidityDays::isZero($stored));
    }
}
