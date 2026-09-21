<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Strategy;

use MerchantQuoteAgentPlugin\Strategy\Strategy;
use MerchantQuoteAgentPlugin\Strategy\StrategyVersion;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Field;

/**
 * The entities must name the tables the migration creates, and must not carry
 * a `maxLength:` argument -- it does not exist at the 6.7.1.0 support floor and
 * an unknown named argument is an Error during the container build.
 */
final class StrategyEntityTest extends TestCase
{
    public function testTheEntitiesNameTheMigratedTables(): void
    {
        self::assertSame('merchant_quote_agent_strategy', self::entityName(Strategy::class));
        self::assertSame('merchant_quote_agent_strategy_version', self::entityName(StrategyVersion::class));
    }

    public function testTheStrategyDeclaresItsColumns(): void
    {
        self::assertSame(['id', 'name', 'description', 'archivedAt'], self::fieldNames(Strategy::class));
    }

    public function testTheVersionDeclaresItsColumns(): void
    {
        self::assertSame(
            ['id', 'strategyId', 'version', 'prompt', 'status', 'runId', 'evaluation', 'rationale', 'decidedAt'],
            self::fieldNames(StrategyVersion::class),
        );
    }

    public function testNoFieldUsesMaxLength(): void
    {
        foreach ([Strategy::class, StrategyVersion::class] as $entity) {
            foreach ((new \ReflectionClass($entity))->getProperties() as $property) {
                foreach ($property->getAttributes(Field::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                    self::assertArrayNotHasKey(
                        'maxLength',
                        $attribute->getArguments(),
                        $entity . '::$' . $property->getName() . ' uses maxLength, which the support floor lacks.',
                    );
                }
            }
        }
    }

    /** @param class-string $class */
    private static function entityName(string $class): string
    {
        $attribute = (new \ReflectionClass($class))->getAttributes(Entity::class)[0] ?? null;
        self::assertNotNull($attribute);

        return (string) ($attribute->getArguments()[0] ?? $attribute->getArguments()['name'] ?? '');
    }

    /**
     * @param class-string $class
     *
     * @return list<string>
     */
    private static function fieldNames(string $class): array
    {
        $names = [];

        foreach ((new \ReflectionClass($class))->getProperties() as $property) {
            if ($property->getAttributes(Field::class, \ReflectionAttribute::IS_INSTANCEOF) !== []) {
                $names[] = $property->getName();
            }
        }

        return $names;
    }
}
