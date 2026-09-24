<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy;

use MerchantQuoteAgentPlugin\Policy\MarginFloorClamp;
use PHPUnit\Framework\TestCase;

/**
 * Later rounds: the reference is the baseline-anchored quote, not the live
 * one. A quote-wide conversion is measured from it, so repeating an ask (or
 * retrying the pass) reproduces the same prices instead of compounding.
 */
final class MarginFloorClampAnchoringTest extends TestCase
{
    public function testARepeatedQuoteWideAskMovesNothing(): void
    {
        // Round one (baseline 120/120, floor 110 on a, 15%) wrote a=110,
        // b=102. The buyer insists and the model proposes 15% again: a lands
        // at 93.50, so the clamp fires, but b's 15% is measured from its
        // baseline 120 -- not from the 102 round one already wrote -- so it
        // stays 102 instead of compounding to 86.70.
        $clamped = MarginFloorClamp::clamp(
            MarginFloorClampTest::quoteWide(15.0),
            [MarginFloorsTest::line('a', 110.0), MarginFloorsTest::line('b', 102.0)],
            [MarginFloorsTest::line('a', 120.0), MarginFloorsTest::line('b', 120.0)],
            ['a' => 110.0],
        );

        self::assertSame(['a' => 110.0, 'b' => 102.0], MarginFloorClampTest::prices($clamped));
    }

    public function testAQuoteWideConversionNeverRaisesALineAboveItsLivePrice(): void
    {
        // A human lowered b to 80 after the baseline; 15% off its baseline 120
        // is 102, which would RAISE it. It stays at 80. A line the reference
        // does not know (c) is converted from its live price.
        $clamped = MarginFloorClamp::clamp(
            MarginFloorClampTest::quoteWide(15.0),
            [
                MarginFloorsTest::line('a', 120.0),
                MarginFloorsTest::line('b', 80.0),
                MarginFloorsTest::line('c', 40.0),
            ],
            [MarginFloorsTest::line('a', 120.0), MarginFloorsTest::line('b', 120.0)],
            ['a' => 110.0],
        );

        self::assertSame(['a' => 110.0, 'b' => 80.0, 'c' => 34.0], MarginFloorClampTest::prices($clamped));
    }
}
