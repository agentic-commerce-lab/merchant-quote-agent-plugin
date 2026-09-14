<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Strategy;

use MerchantQuoteAgentPlugin\Migration\Migration1789400001SeedBuiltInStrategies;
use MerchantQuoteAgentPlugin\Strategy\BuiltInStrategies;
use PHPUnit\Framework\TestCase;

/**
 * A second copy of the prompt text inside the migration would drift from
 * BuiltInStrategies the first time either changed, and the drift would only
 * ever show up on a freshly installed shop. So the migration must read the
 * class, and this test reads the migration's source to prove it does.
 */
final class SeedMigrationTest extends TestCase
{
    private static function source(): string
    {
        $file = (new \ReflectionClass(Migration1789400001SeedBuiltInStrategies::class))->getFileName();
        self::assertIsString($file);

        $source = file_get_contents($file);
        self::assertIsString($source);

        return $source;
    }

    public function testTheMigrationReadsTheBuiltInDefinitions(): void
    {
        self::assertStringContainsString('BuiltInStrategies::all()', self::source());
    }

    public function testTheMigrationDoesNotCopyThePromptText(): void
    {
        $source = self::source();

        foreach (BuiltInStrategies::all() as $definition) {
            $firstSentence = strtok($definition['prompt'], '.');
            self::assertIsString($firstSentence);

            self::assertStringNotContainsString(
                $firstSentence,
                $source,
                'The migration carries its own copy of a built-in prompt; it must read BuiltInStrategies instead.',
            );
        }
    }

    public function testTheTimestampIsAfterTheTableCreation(): void
    {
        self::assertGreaterThan(1789400000, (new Migration1789400001SeedBuiltInStrategies())->getCreationTimestamp());
    }
}
