<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Improvement;

use MerchantQuoteAgentPlugin\Improvement\ReplayArm;
use MerchantQuoteAgentPlugin\Improvement\ReplayScore;
use PHPUnit\Framework\TestCase;

final class ReplayScoreTest extends TestCase
{
    public function testAnEmptySampleScoresZeroAndNotNaN(): void
    {
        $score = ReplayScore::of([]);

        self::assertSame(0, $score->sampled);
        self::assertSame(0.0, $score->escalationRate);
        self::assertNull($score->meanGrantedPercent);
    }

    public function testFailedArmsAreCountedButKeptOutOfTheRates(): void
    {
        // A provider outage must not read as "this prompt escalates less".
        $score = ReplayScore::of([
            ReplayArm::offered(10.0),
            ReplayArm::escalated(),
            ReplayArm::failed(),
        ]);

        self::assertSame(2, $score->sampled);
        self::assertSame(0.5, $score->escalationRate);
        self::assertSame(10.0, $score->meanGrantedPercent);
        self::assertSame(1, $score->failures);
    }

    public function testAnOfferWithNoMeasurableDiscountDoesNotSkewTheMean(): void
    {
        $score = ReplayScore::of([ReplayArm::offered(8.0), ReplayArm::offered(null)]);

        self::assertSame(8.0, $score->meanGrantedPercent);
    }

    public function testAnUnmeasurableOfferIsCountedButNotAnEscalationOrAMeanInput(): void
    {
        // Task 8: a per-line offer replays as offered(null) -- an offer WAS
        // made, but its concession could not be measured offline. It must
        // show up as its own number, not vanish into the escalation rate or
        // silently shrink the mean's denominator.
        $score = ReplayScore::of([ReplayArm::offered(null)]);

        self::assertSame(1, $score->unmeasured);
        self::assertSame(0.0, $score->escalationRate);
        self::assertNull($score->meanGrantedPercent);
    }

    public function testModelRefusalsAreTrackedSeparatelyFromEscalations(): void
    {
        $score = ReplayScore::of([ReplayArm::modelRefused()]);

        self::assertSame(1, $score->modelRefusals);
        self::assertSame(1.0, $score->escalationRate);
    }
}
