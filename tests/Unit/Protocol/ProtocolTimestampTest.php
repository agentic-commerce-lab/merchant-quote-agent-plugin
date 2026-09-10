<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol;

use MerchantQuoteAgentPlugin\Protocol\ProtocolTimestamp;
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
}
