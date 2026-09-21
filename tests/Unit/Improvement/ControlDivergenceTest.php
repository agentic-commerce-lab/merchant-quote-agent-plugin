<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Improvement;

use MerchantQuoteAgentPlugin\Improvement\ControlDivergence;
use PHPUnit\Framework\TestCase;

final class ControlDivergenceTest extends TestCase
{
    public function testFifteenPointsIsNotYetDivergence(): void
    {
        self::assertFalse(ControlDivergence::diverged(45.0, 30.0));
    }

    public function testMoreThanFifteenPointsIs(): void
    {
        self::assertTrue(ControlDivergence::diverged(46.0, 30.0));
        self::assertTrue(ControlDivergence::diverged(10.0, 30.0));
    }

    public function testIdenticalRatesAreNeverDivergence(): void
    {
        // Equal-rate is the classic off-by-one case for a threshold comparison.
        self::assertFalse(ControlDivergence::diverged(0.0, 0.0));
        self::assertFalse(ControlDivergence::diverged(100.0, 100.0));
    }
}
