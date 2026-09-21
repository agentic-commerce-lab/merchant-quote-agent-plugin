<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Strategy;

use MerchantQuoteAgentPlugin\Migration\Migration1789600000CreateStrategyAssignment;
use MerchantQuoteAgentPlugin\Strategy\StrategyAssignment;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Field;

/**
 * Attribute entities carry no schema generator, so the class and the DDL are
 * kept in step by hand. This is the test that notices when they are not.
 */
final class StrategyAssignmentEntityTest extends TestCase
{
    public function testTheEntityNamesTheMigratedTable(): void
    {
        $attribute = (new \ReflectionClass(StrategyAssignment::class))->getAttributes(Entity::class)[0]->newInstance();

        self::assertSame('merchant_quote_agent_strategy_assignment', $attribute->name);
    }

    /**
     * Property name to snake_case column, checked against the CREATE TABLE
     * text itself rather than a second hand-maintained list.
     */
    public function testEveryEntityPropertyHasAColumn(): void
    {
        $ddl = file_get_contents(__DIR__ . '/../../../src/Migration/Migration1789600000CreateStrategyAssignment.php');
        self::assertIsString($ddl);

        foreach ((new \ReflectionClass(StrategyAssignment::class))->getProperties() as $property) {
            // Declared properties only. The DAL's Entity base contributes
            // _uniqueIdentifier, versionId, translated, _entityName,
            // _fieldVisibility and extensions, none of which are columns here.
            if ($property->getDeclaringClass()->getName() !== StrategyAssignment::class) {
                continue;
            }

            $column = strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1_$2', $property->getName()));

            self::assertStringContainsString('`' . $column . '`', $ddl, $property->getName());
        }
    }

    public function testNoFieldDeclaresMaxLength(): void
    {
        foreach ((new \ReflectionClass(StrategyAssignment::class))->getProperties() as $property) {
            foreach ($property->getAttributes(Field::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                self::assertArrayNotHasKey(
                    'maxLength',
                    $attribute->getArguments(),
                    StrategyAssignment::class
                    . '::$'
                    . $property->getName()
                    . ' uses maxLength, which the support floor lacks.',
                );
            }
        }
    }
}
