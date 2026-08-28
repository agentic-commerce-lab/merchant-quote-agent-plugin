<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Config;

use MerchantQuoteAgentPlugin\Config\VolumeTierParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VolumeTierParserTest extends TestCase
{
    public function testItParsesOneTierPerLine(): void
    {
        self::assertSame(
            [
                ['minQty' => 10, 'discountPercent' => 5.0],
                ['minQty' => 50, 'discountPercent' => 7.5],
            ],
            VolumeTierParser::parse("10:5\n50:7.5"),
        );
    }

    public function testBlankLinesAndSurroundingWhitespaceAreIgnored(): void
    {
        self::assertSame([['minQty' => 10, 'discountPercent' => 5.0]], VolumeTierParser::parse("\n  10 : 5  \n\n"));
    }

    public function testAnEmptyTextareaIsAnEmptyLadderRatherThanAFailure(): void
    {
        self::assertSame([], VolumeTierParser::parse('   '));
    }

    public function testWindowsLineEndingsParse(): void
    {
        self::assertSame([['minQty' => 10, 'discountPercent' => 5.0]], VolumeTierParser::parse("10:5\r\n"));
    }

    /** @return iterable<string, array{0: string, 1: int}> */
    public static function malformed(): iterable
    {
        yield 'no separator' => ["10\n", 1];
        yield 'non-numeric quantity' => ["abc:5\n", 1];
        yield 'non-numeric percent' => ["10:abc\n", 1];
        yield 'fractional quantity' => ["10.5:5\n", 1];
        yield 'too many parts' => ["10:5:7\n", 1];
        yield 'reported on the offending line, not the first' => ["10:5\n50:7.5\nbroken\n", 3];
    }

    #[DataProvider('malformed')]
    public function testAMalformedLineFailsAndNamesItsLineNumber(string $text, int $line): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage(sprintf('line %d', $line));

        VolumeTierParser::parse($text);
    }
}
