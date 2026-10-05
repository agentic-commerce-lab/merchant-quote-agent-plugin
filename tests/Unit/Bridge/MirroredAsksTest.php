<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge;

use MerchantQuoteAgentPlugin\Bridge\MirroredAsk;
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
        $asks = ['line-1' => MirroredAsk::written(15.0, 1.0), 'line-2' => MirroredAsk::written(7.5, 1.0)];

        self::assertEquals($asks, MirroredAsks::read(MirroredAsks::stamp([], $asks)));
    }

    public function testAnAbsentOrCorruptMarkerReadsAsNothingMirrored(): void
    {
        self::assertSame([], MirroredAsks::read([]));
        self::assertSame([], MirroredAsks::read([MirroredAsks::KEY => 'corrupt']));
    }

    public function testStampingKeepsAnEarlierRoundsMirrorOnOtherLines(): void
    {
        $stamped = MirroredAsks::stamp(MirroredAsks::stamp([], ['line-1' => MirroredAsk::written(15.0, 1.0)]), [
            'line-2' => MirroredAsk::written(7.5, 1.0),
        ]);

        self::assertSame(['line-1', 'line-2'], array_keys(MirroredAsks::read($stamped)));
    }

    public function testThisRoundsMirrorWinsOnALineEarlierRoundsAlsoMirrored(): void
    {
        $stamped = MirroredAsks::stamp(MirroredAsks::stamp([], ['line-1' => MirroredAsk::written(15.0, 1.0)]), [
            'line-1' => MirroredAsk::written(12.0, 1.0),
        ]);

        self::assertEquals(['line-1' => MirroredAsk::written(12.0, 1.0)], MirroredAsks::read($stamped));
    }

    public function testANonNumericEntryIsSkippedRatherThanFailingTheWholeMarker(): void
    {
        $read = MirroredAsks::read([
            MirroredAsks::KEY => [
                'line-1' => 15.0,
                'line-2' => 'free',
                'line-3' => ['net' => 9.0],
                'line-4' => ['net' => 9.0, 'stored' => 'ten'],
            ],
        ]);

        self::assertSame(['line-1'], array_keys($read));
    }

    /** Custom fields survive a JSON round trip, so a whole number comes back as an int. */
    public function testAWholeNumberStoredAsAnIntStillReadsAsAPrice(): void
    {
        $read = MirroredAsks::read([MirroredAsks::KEY => ['line-1' => 15, 'line-2' => ['net' => 15, 'stored' => 18]]]);

        self::assertSame(15.0, $read['line-1']->net ?? null);
        self::assertSame(18.0, $read['line-2']->stored ?? null);
    }

    public function testAMirroredPriceIsRecognisedInTheStoredTaxSpace(): void
    {
        self::assertTrue(MirroredAsks::holds(['line-1' => MirroredAsk::written(15.0, 1.0)], 'line-1', 15.0, 1.0));
        self::assertTrue(MirroredAsks::holds(
            ['line-1' => MirroredAsk::written(15.0, 100 / 119)],
            'line-1',
            17.85,
            100 / 119,
        ));
    }

    public function testABuyersOwnNumberIsNotRecognisedAsMirrored(): void
    {
        $net = ['line-1' => MirroredAsk::written(15.0, 1.0)];
        $gross = ['line-1' => MirroredAsk::written(15.0, 100 / 119)];

        self::assertFalse(MirroredAsks::holds($net, 'line-1', 14.99, 1.0));
        self::assertFalse(MirroredAsks::holds($gross, 'line-1', 17.84, 100 / 119));
        self::assertFalse(MirroredAsks::holds($net, 'line-2', 15.0, 1.0));
        self::assertFalse(MirroredAsks::holds([], 'line-1', 15.0, 1.0));
        self::assertFalse(MirroredAsks::holds($net, 'line-1', null, 1.0));
    }

    /**
     * QA-08, quote #1411: 289.81 net was stored as 318.79 at 305.06/335.57.
     * The offer then repriced the line to 293.05 net / 322.36 gross, and
     * converting 289.81 through THAT ratio gives 318.80. A cent off, so the
     * agent read its own mirror as a fresh buyer ask and cut the price again,
     * silently.
     */
    public function testAMirrorStillHoldsAfterTheAgentRepricedTheLine(): void
    {
        $mirrored = MirroredAsks::read(MirroredAsks::stamp([], [
            'line-1' => MirroredAsk::written(289.81, 305.06 / 335.57),
        ]));

        self::assertTrue(MirroredAsks::holds($mirrored, 'line-1', 318.79, 293.05 / 322.36));
        self::assertFalse(
            MirroredAsks::holds($mirrored, 'line-1', 318.80, 293.05 / 322.36),
            'The recorded value decides, not a conversion through the line\'s current ratio.',
        );
    }

    /**
     * An entry written before QA-08 holds the net ask alone. It is read as it
     * always was, and re-stamping another line must not invent a stored value
     * nobody recorded for it.
     */
    public function testALegacyEntryIsReadAsItWasAndStaysLegacy(): void
    {
        $legacy = MirroredAsks::read([MirroredAsks::KEY => ['line-1' => 15.0]]);

        self::assertNull($legacy['line-1']->stored ?? null);
        self::assertTrue(MirroredAsks::holds($legacy, 'line-1', 17.85, 100 / 119));
        self::assertFalse(MirroredAsks::holds($legacy, 'line-1', 17.84, 100 / 119));
        self::assertSame(
            [MirroredAsks::KEY => ['line-1' => 15.0, 'line-2' => ['net' => 7.5, 'stored' => 8.93]]],
            MirroredAsks::stamp([MirroredAsks::KEY => ['line-1' => 15.0]], [
                'line-2' => MirroredAsk::written(7.5, 100 / 119),
            ]),
        );
    }
}
