<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Strategy;

use MerchantQuoteAgentPlugin\Strategy\BuiltInStrategies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The brief requires the three built-ins to load its exact prompts. This is
 * the test that mechanises that, and it compares BYTES: the texts contain
 * typographic apostrophes, and an editor normalising them to ASCII would
 * change the prompt with nothing else noticing.
 */
final class BuiltInStrategiesTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function builtIns(): iterable
    {
        yield 'margin defender' => [BuiltInStrategies::MARGIN_DEFENDER, 'margin-defender'];
        yield 'fast close' => [BuiltInStrategies::FAST_CLOSE, 'fast-close'];
        yield 'relationship builder' => [BuiltInStrategies::RELATIONSHIP_BUILDER, 'relationship-builder'];
    }

    #[DataProvider('builtIns')]
    public function testThePromptMatchesTheBriefByteForByte(string $id, string $fixture): void
    {
        $expected = file_get_contents(__DIR__ . '/../../Fixtures/Strategy/' . $fixture . '.txt');
        self::assertIsString($expected);

        self::assertSame($expected, BuiltInStrategies::all()[$id]['prompt']);
    }

    #[DataProvider('builtIns')]
    public function testThePromptCarriesNoAsciiApostrophe(string $id, string $fixture): void
    {
        self::assertSame(
            0,
            substr_count(BuiltInStrategies::all()[$id]['prompt'], "'"),
            $fixture . ': an ASCII apostrophe appeared in a built-in prompt; the brief uses typographic ones.',
        );
    }

    public function testEveryIdIsAThirtyTwoCharacterHexUuid(): void
    {
        foreach (BuiltInStrategies::IDS as $id) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
        }
    }

    public function testTheIdsAreDistinct(): void
    {
        self::assertCount(3, array_unique(BuiltInStrategies::IDS));
    }

    public function testEveryIdHasANameADescriptionAndAPrompt(): void
    {
        foreach (BuiltInStrategies::IDS as $id) {
            $entry = BuiltInStrategies::all()[$id] ?? null;

            self::assertIsArray($entry, 'No entry for ' . $id);
            self::assertNotSame('', $entry['name']);
            self::assertNotSame('', $entry['description']);
            self::assertNotSame('', $entry['prompt']);
        }
    }

    public function testIsBuiltInRecognisesTheSeededIdsAndNothingElse(): void
    {
        self::assertTrue(BuiltInStrategies::isBuiltIn(BuiltInStrategies::FAST_CLOSE));
        self::assertFalse(BuiltInStrategies::isBuiltIn('0123456789abcdef0123456789abcdef'));
    }
}
