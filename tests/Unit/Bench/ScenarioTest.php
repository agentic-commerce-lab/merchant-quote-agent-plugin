<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bench;

use MerchantQuoteAgentPlugin\Tests\Bench\Scenario;
use PHPUnit\Framework\TestCase;

final class ScenarioTest extends TestCase
{
    public function testARoundCapIsMandatoryBecauseNothingElseBoundsTheLoop(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/maxRounds/');

        Scenario::fromArray([
            'id' => 'plain-percentage',
            'description' => 'A five percent ask inside the band.',
            'lines' => [['productRef' => 'any-purchasable', 'quantity' => 3]],
            'openingAsk' => 'Could you do 5% off?',
            'persona' => 'scripted:moderate',
        ]);
    }

    public function testAScenarioRoundTripsThroughItsArrayForm(): void
    {
        $scenario = Scenario::fromArray([
            'id' => 'plain-percentage',
            'description' => 'A five percent ask inside the band.',
            'lines' => [['productRef' => 'any-purchasable', 'quantity' => 3]],
            'openingAsk' => 'Could you do 5% off?',
            'persona' => 'scripted:moderate',
            'maxRounds' => 6,
            'expectedBand' => 'auto',
        ]);

        self::assertSame('plain-percentage', $scenario->id);
        self::assertSame(6, $scenario->maxRounds);
        self::assertSame('auto', $scenario->expectedBand);
        self::assertSame(3, $scenario->lines[0]['quantity']);
    }

    public function testAnAbsentExpectedBandIsNullRatherThanAGuess(): void
    {
        // Not every scenario asserts an outcome; some exist to observe one.
        $scenario = Scenario::fromArray([
            'id' => 'plain-percentage',
            'description' => 'A five percent ask inside the band.',
            'lines' => [['productRef' => 'any-purchasable', 'quantity' => 3]],
            'openingAsk' => 'Could you do 5% off?',
            'persona' => 'scripted:moderate',
            'maxRounds' => 4,
        ]);

        self::assertNull($scenario->expectedBand);
    }

    public function testARoundCapOfZeroIsRefused(): void
    {
        // A scenario that cannot take a single round is a typo, not a
        // configuration — it would report "no offer" for every strategy and
        // look like a finding.
        $this->expectException(\InvalidArgumentException::class);

        Scenario::fromArray([
            'id' => 'plain-percentage',
            'description' => 'A five percent ask inside the band.',
            'lines' => [['productRef' => 'any-purchasable', 'quantity' => 3]],
            'openingAsk' => 'Could you do 5% off?',
            'persona' => 'scripted:moderate',
            'maxRounds' => 0,
        ]);
    }

    public function testLoadReadsAScenarioFromAJsonFile(): void
    {
        $scenario = Scenario::load(__DIR__ . '/../../Bench/scenarios/plain-percentage.json');

        self::assertSame('plain-percentage', $scenario->id);
        self::assertSame(6, $scenario->maxRounds);
        self::assertSame('any-purchasable', $scenario->lines[0]['productRef']);
    }

    public function testLoadNamesTheFileWhenTheFieldIsInvalid(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scenario') . '.json';
        file_put_contents(
            $path,
            '{"id": "broken", "description": "d", "lines": [], "openingAsk": "a", "persona": "p", "maxRounds": 1}',
        );

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessageMatches('/' . preg_quote($path, '/') . '/');

            Scenario::load($path);
        } finally {
            unlink($path);
        }
    }

    public function testAllLoadsEveryScenarioInADirectorySortedByFilename(): void
    {
        $scenarios = Scenario::all(__DIR__ . '/../../Bench/scenarios');

        self::assertNotEmpty($scenarios);
        self::assertContainsOnlyInstancesOf(Scenario::class, $scenarios);
        self::assertSame('plain-percentage', $scenarios[0]->id);
    }
}
