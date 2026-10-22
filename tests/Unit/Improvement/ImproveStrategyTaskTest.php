<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Improvement;

use MerchantQuoteAgentPlugin\Improvement\ImproveStrategyTask;
use PHPUnit\Framework\TestCase;

final class ImproveStrategyTaskTest extends TestCase
{
    public function testItNamesItself(): void
    {
        self::assertSame('merchant_quote_agent.improve_strategy', ImproveStrategyTask::getTaskName());
    }

    /**
     * 86400 seconds, one day: the merchant's own cadence is enforced inside
     * the run, not by core's scheduler -- see ImprovementWindow.
     */
    public function testItDefaultsToADailyInterval(): void
    {
        self::assertSame(86400, ImproveStrategyTask::getDefaultInterval());
    }

    /** A worker that missed a night must still try again the next one. */
    public function testItReschedulesAfterAFailure(): void
    {
        self::assertTrue(ImproveStrategyTask::shouldRescheduleOnFailure());
    }
}
