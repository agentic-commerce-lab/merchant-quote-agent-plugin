<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Improvement;

use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Improvement\ImprovementCadence;
use MerchantQuoteAgentPlugin\Improvement\ImprovementSettings;
use PHPUnit\Framework\TestCase;

final class ImprovementSettingsTest extends TestCase
{
    public function testItClampsAnAbsurdSampleSize(): void
    {
        self::assertSame(100, $this->settings(sampleSize: 5000)->sampleSize);
        self::assertSame(1, $this->settings(sampleSize: 0)->sampleSize);
        self::assertSame(1, $this->settings(sampleSize: -20)->sampleSize);
    }

    public function testItClampsTheCandidateCount(): void
    {
        self::assertSame(4, $this->settings(candidates: 99)->candidates);
        self::assertSame(1, $this->settings(candidates: 0)->candidates);
    }

    public function testCadenceIsInDays(): void
    {
        self::assertSame(1, ImprovementCadence::Daily->days());
        self::assertSame(3, ImprovementCadence::EveryThreeDays->days());
        self::assertSame(7, ImprovementCadence::Weekly->days());
    }

    public function testFromRawFallsBackToDailyOnGarbage(): void
    {
        self::assertSame(ImprovementCadence::Daily, ImprovementCadence::fromRaw('not-a-cadence'));
        self::assertSame(ImprovementCadence::Daily, ImprovementCadence::fromRaw(null));
        self::assertSame(ImprovementCadence::Weekly, ImprovementCadence::fromRaw('weekly'));
    }

    private function settings(int $sampleSize = 20, int $candidates = 2): ImprovementSettings
    {
        return new ImprovementSettings(
            true,
            ImprovementCadence::Daily,
            $sampleSize,
            $candidates,
            new ModelAccess('key', 'https://example.test/v1', 'gpt-4o-mini'),
        );
    }
}
