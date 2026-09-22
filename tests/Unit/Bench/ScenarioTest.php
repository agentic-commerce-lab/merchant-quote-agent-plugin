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
        $ids = array_map(static fn(Scenario $scenario): string => $scenario->id, $scenarios);

        self::assertNotEmpty($scenarios);
        self::assertContainsOnlyInstancesOf(Scenario::class, $scenarios);
        self::assertContains('plain-percentage', $ids);
        // Sorted by FILENAME: Task 7 added scenario files whose names sort
        // before 'plain-percentage.json', so position 0 is no longer pinned
        // to it here -- testTheFullScenarioSetLoadsWithoutError() below pins
        // the exact order; this one only pins the general sortedness.
        $sorted = $ids;
        sort($sorted);
        self::assertSame($sorted, $ids);
    }

    /**
     * The bench's own regression gate on the scenario set (Task 7): every
     * failure class the set is meant to cover must actually be present, by
     * id, and nothing else must throw while loading. A malformed scenario
     * must fail here -- three model calls before a paid matrix run, not
     * during one.
     */
    public function testTheFullScenarioSetLoadsWithoutError(): void
    {
        $scenarios = Scenario::all(__DIR__ . '/../../Bench/scenarios');

        self::assertSame(
            [
                'ambiguous-ask',
                'bundle-ask',
                'exactly-at-the-ceiling',
                'gross-figure-in-comment',
                'hostile-extraction',
                'multi-round-anchoring',
                'payment-terms-ask',
                'plain-percentage',
                'structured-only',
                'volume-ask',
            ],
            array_map(static fn(Scenario $scenario): string => $scenario->id, $scenarios),
            'One scenario per file, sorted by filename -- add a new *.json here and this list, never silently.',
        );

        foreach ($scenarios as $scenario) {
            // structured-only is the one deliberate exception: its entire
            // point is a per-line ask with no comment at all, so its
            // openingAsk is empty on purpose -- see BenchNegotiation::run().
            if ($scenario->id !== 'structured-only') {
                self::assertNotSame(
                    '',
                    $scenario->openingAsk,
                    sprintf('%s: openingAsk must not be empty.', $scenario->id),
                );
            }
            self::assertGreaterThanOrEqual(
                1,
                $scenario->maxRounds,
                sprintf('%s: maxRounds must be >= 1.', $scenario->id),
            );
            self::assertNotEmpty($scenario->lines, sprintf('%s: lines must not be empty.', $scenario->id));
        }
    }

    public function testARequestedUnitPriceRoundTripsThroughItsArrayForm(): void
    {
        $scenario = Scenario::fromArray([
            'id' => 'structured-only',
            'description' => 'A per-line requested price with no comment about it at all.',
            'lines' => [['productRef' => 'any-purchasable', 'quantity' => 10, 'requestedUnitPrice' => 80.0]],
            'openingAsk' => '',
            'persona' => 'scripted:silent',
            'maxRounds' => 2,
        ]);

        self::assertSame(80.0, $scenario->lines[0]['requestedUnitPrice']);
    }

    public function testALineWithoutARequestedUnitPriceLeavesItNullRatherThanDefaulting(): void
    {
        // Not a number, not zero -- null, so BenchNegotiation can tell "no
        // ask" apart from "an ask of 0" and omit the key entirely rather
        // than send a price nobody asked for.
        $scenario = Scenario::fromArray([
            'id' => 'plain-percentage',
            'description' => 'A five percent ask inside the band.',
            'lines' => [['productRef' => 'any-purchasable', 'quantity' => 3]],
            'openingAsk' => 'Could you do 5% off?',
            'persona' => 'scripted:moderate',
            'maxRounds' => 4,
        ]);

        self::assertNull($scenario->lines[0]['requestedUnitPrice']);
    }
}
