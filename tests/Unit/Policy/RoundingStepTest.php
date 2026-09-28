<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy;

use MerchantQuoteAgentPlugin\Policy\RoundingStep;
use PHPUnit\Framework\TestCase;

final class RoundingStepTest extends TestCase
{
    public function testDownFloorsToAMultipleOfTheStep(): void
    {
        self::assertSame(7.0, RoundingStep::down(7.34, 0.5));
        self::assertSame(0.0, RoundingStep::down(0.3, 0.5));
    }

    public function testAFigureAlreadyOnTheStepSurvivesFloatNoise(): void
    {
        // 7.5 / 0.5 and 8.2 / 0.1 are 14.999… and 81.999… in binary floating point.
        self::assertSame(7.5, RoundingStep::down(7.5, 0.5));
        self::assertSame(8.2, RoundingStep::down(8.2, 0.1));
        self::assertSame(1350.0, RoundingStep::up(1350.0, 10.0));
        self::assertSame(0.3, RoundingStep::up(0.1 + 0.2, 0.1));
    }

    public function testUpRaisesToTheNextMultipleOfTheStep(): void
    {
        self::assertSame(1360.0, RoundingStep::up(1356.47, 10.0));
        self::assertSame(1357.0, RoundingStep::up(1356.47, 1.0));
    }

    public function testTheBuyersFigureIsMatchedToTwoDecimals(): void
    {
        self::assertTrue(RoundingStep::isBuyersFigure(7.34, 7.34));
        // A budget of 2500 on 2614.05 is 4.3629…%; the model answers 4.36.
        self::assertTrue(RoundingStep::isBuyersFigure(4.36, 4.3629));
        self::assertFalse(RoundingStep::isBuyersFigure(7.34, 8.0));
        self::assertFalse(RoundingStep::isBuyersFigure(7.34, null));
        self::assertFalse(RoundingStep::isBuyersFigure(null, 7.34));
    }
}
