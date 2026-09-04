<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Terms;

use MerchantQuoteAgentPlugin\Protocol\Terms\MinorUnits;
use MerchantQuoteAgentPlugin\Protocol\Terms\NonFiniteAmount;
use PHPUnit\Framework\TestCase;

final class MinorUnitsTest extends TestCase
{
    /**
     * Half away from zero, symmetrically: the quote discount is a negative line
     * item, so -1.005 and 1.005 must round to the same magnitude.
     */
    public function testItRoundsHalvesAwayFromZeroSymmetrically(): void
    {
        self::assertSame(101, MinorUnits::from(1.005));
        self::assertSame(-101, MinorUnits::from(-1.005));
        self::assertSame(1, MinorUnits::from(0.005));
        self::assertSame(-1, MinorUnits::from(-0.005));
    }

    public function testItConvertsOrdinaryAmounts(): void
    {
        self::assertSame(0, MinorUnits::from(0.0));
        self::assertSame(76000, MinorUnits::from(760.0));
        self::assertSame(79999, MinorUnits::from(799.99));
    }

    public function testItRefusesNonFiniteAmounts(): void
    {
        $this->expectException(NonFiniteAmount::class);

        MinorUnits::from(\NAN);
    }

    public function testItRefusesInfinity(): void
    {
        $this->expectException(NonFiniteAmount::class);

        MinorUnits::from(\INF);
    }
}
