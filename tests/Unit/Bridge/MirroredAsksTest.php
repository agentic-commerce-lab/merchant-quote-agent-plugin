<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge;

use MerchantQuoteAgentPlugin\Bridge\MirroredAsks;
use PHPUnit\Framework\TestCase;

/**
 * The marker that tells the agent's own write apart from a buyer's. Every
 * guard that treats `requested_price` as buyer-only reads the line through
 * QuoteLineMapper, so a marker that fails to parse must degrade to "the buyer
 * wrote it" — reporting a real ask twice is recoverable, dropping one is not.
 */
final class MirroredAsksTest extends TestCase
{
    public function testItRoundTripsWhatItStamped(): void
    {
        $stamped = MirroredAsks::stamp([], ['line-1' => 15.0, 'line-2' => 7.5]);

        self::assertSame(['line-1' => 15.0, 'line-2' => 7.5], MirroredAsks::read($stamped));
    }

    public function testAnAbsentMarkerReadsAsNothingMirrored(): void
    {
        self::assertSame([], MirroredAsks::read([]));
    }

    public function testStampingKeepsAnEarlierRoundsMirrorOnOtherLines(): void
    {
        $stamped = MirroredAsks::stamp(MirroredAsks::stamp([], ['line-1' => 15.0]), ['line-2' => 7.5]);

        self::assertSame(['line-1' => 15.0, 'line-2' => 7.5], MirroredAsks::read($stamped));
    }

    public function testThisRoundsMirrorWinsOnALineEarlierRoundsAlsoMirrored(): void
    {
        $stamped = MirroredAsks::stamp(MirroredAsks::stamp([], ['line-1' => 15.0]), ['line-1' => 12.0]);

        self::assertSame(['line-1' => 12.0], MirroredAsks::read($stamped));
    }

    public function testAMarkerThatIsNotAnArrayReadsAsNothingMirrored(): void
    {
        self::assertSame([], MirroredAsks::read([MirroredAsks::KEY => 'corrupt']));
    }

    public function testANonNumericEntryIsSkippedRatherThanFailingTheWholeMarker(): void
    {
        $read = MirroredAsks::read([MirroredAsks::KEY => ['line-1' => 15.0, 'line-2' => 'free']]);

        self::assertSame(['line-1' => 15.0], $read);
    }

    /** Custom fields survive a JSON round trip, so a whole number comes back as an int. */
    public function testAWholeNumberStoredAsAnIntStillReadsAsAPrice(): void
    {
        self::assertSame(['line-1' => 15.0], MirroredAsks::read([MirroredAsks::KEY => ['line-1' => 15]]));
    }

    public function testAMirroredPriceIsRecognisedInTheStoredTaxSpace(): void
    {
        self::assertTrue(MirroredAsks::holds(['line-1' => 15.0], 'line-1', 15.0, 1.0));
        self::assertTrue(MirroredAsks::holds(['line-1' => 15.0], 'line-1', 17.85, 100 / 119));
    }

    public function testABuyersOwnNumberIsNotRecognisedAsMirrored(): void
    {
        self::assertFalse(MirroredAsks::holds(['line-1' => 15.0], 'line-1', 14.99, 1.0));
        self::assertFalse(MirroredAsks::holds(['line-1' => 15.0], 'line-1', 17.84, 100 / 119));
        self::assertFalse(MirroredAsks::holds(['line-1' => 15.0], 'line-2', 15.0, 1.0));
        self::assertFalse(MirroredAsks::holds([], 'line-1', 15.0, 1.0));
        self::assertFalse(MirroredAsks::holds(['line-1' => 15.0], 'line-1', null, 1.0));
    }
}
