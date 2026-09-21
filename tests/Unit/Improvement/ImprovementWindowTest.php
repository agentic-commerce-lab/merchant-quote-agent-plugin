<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Improvement;

use MerchantQuoteAgentPlugin\Improvement\ImprovementCadence;
use MerchantQuoteAgentPlugin\Improvement\ImprovementWindow;
use PHPUnit\Framework\TestCase;

final class ImprovementWindowTest extends TestCase
{
    private const NOW = '2026-09-21 03:00:00';

    public function testTheFirstRunLooksBackOneCadence(): void
    {
        $window = ImprovementWindow::due(null, $this->at(self::NOW), ImprovementCadence::Weekly);

        self::assertNotNull($window);
        self::assertSame('2026-09-14 03:00:00', $window->from->format('Y-m-d H:i:s'));
        self::assertSame(self::NOW, $window->to->format('Y-m-d H:i:s'));
    }

    public function testItIsNotDueBeforeTheCadenceHasPassed(): void
    {
        self::assertNull(ImprovementWindow::due(
            $this->at('2026-09-20 03:00:00'),
            $this->at(self::NOW),
            ImprovementCadence::Weekly,
        ));
    }

    public function testItIsDueExactlyOnTheCadence(): void
    {
        self::assertNotNull(ImprovementWindow::due(
            $this->at('2026-09-14 03:00:00'),
            $this->at(self::NOW),
            ImprovementCadence::Weekly,
        ));
    }

    public function testAMissedNightIsAbsorbedRatherThanLost(): void
    {
        // Daily cadence, but the worker was down for three days: the window
        // starts at the last completed run, not 24h ago, so nothing in
        // between goes unexamined.
        $window = ImprovementWindow::due(
            $this->at('2026-09-18 03:00:00'),
            $this->at(self::NOW),
            ImprovementCadence::Daily,
        );

        self::assertNotNull($window);
        self::assertSame('2026-09-18 03:00:00', $window->from->format('Y-m-d H:i:s'));
    }

    private function at(string $moment): \DateTimeImmutable
    {
        return new \DateTimeImmutable($moment, new \DateTimeZone('UTC'));
    }
}
