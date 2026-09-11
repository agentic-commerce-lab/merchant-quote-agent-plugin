<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol;

use MerchantQuoteAgentPlugin\Protocol\ProtocolTimestamp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * FORMAT and PATTERN are two independent spellings of one rule, kept apart on
 * purpose (see the class docblock) but with nothing that stops them drifting.
 * This is the guard: `of()`'s own output must always satisfy `matches()`, for
 * a UTC instant and for one that needs converting first.
 */
final class ProtocolTimestampTest extends TestCase
{
    public function testOwnOutputMatchesItsOwnPatternForAUtcInstant(): void
    {
        $at = new \DateTimeImmutable('2026-09-05T10:00:00+00:00');

        self::assertTrue(ProtocolTimestamp::matches(ProtocolTimestamp::of($at)));
    }

    public function testOwnOutputMatchesItsOwnPatternAfterConvertingFromANonUtcTimezone(): void
    {
        $at = new \DateTimeImmutable('2026-09-05T12:00:00+02:00');

        self::assertTrue(ProtocolTimestamp::matches(ProtocolTimestamp::of($at)));
    }

    /**
     * Shape-valid but not a real instant. `strtotime()` alone would roll every
     * one of these to SOME instant instead of refusing them — that silent
     * rollover is exactly what let one bad input opt a counterparty out of
     * ordering entirely (#112/#113); the round-trip in `matches()` is the
     * fix, so each of these must come back false, not just the two the
     * pattern already rejects on digit shape.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function unrealTimestamps(): iterable
    {
        yield 'nonsense digits' => ['2026-99-99T99:99:99Z'];
        yield 'day rolls silently past February' => ['2026-02-30T00:00:00Z'];
        yield 'month 13' => ['2026-13-01T00:00:00Z'];
        yield 'hour 25' => ['2026-09-10T25:00:00Z'];
        yield 'a leap second' => ['2026-09-10T23:59:60Z'];
    }

    #[DataProvider('unrealTimestamps')]
    public function testItRefusesAShapeValidTimestampThatIsNotARealInstant(string $value): void
    {
        self::assertFalse(ProtocolTimestamp::matches($value));
    }
}
