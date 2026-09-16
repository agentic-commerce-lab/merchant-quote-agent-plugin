<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge\Commercial;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability;
use MerchantQuoteAgentPlugin\MerchantQuoteAgentPlugin;
use Symfony\Component\DependencyInjection\Argument\ArgumentInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * `src/Resources/config/services.php` built four times: both runtime gates —
 * SwagCommercial and the UCP SDK bundle — crossed.
 *
 * Not a test. It is the fixture CommercialSurfaceConfigurationTest asserts
 * over, split out because the building is a different job from the asserting
 * and because the two together put one class over the lint gate's method
 * count. Never instantiate it outside a process that can afford
 * `makeCommercialClassesAvailable()`: see build().
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * Both rules aggregate per class against a threshold of 10. No method here is
 * individually complex — the count is the sum of the guard clauses that make
 * this a faithful reading of AutowirePass rather than an approximate one, and
 * every one of them corresponds to a case Symfony itself special-cases
 * (factories, explicit arguments, optional parameters, builtin types).
 * Dropping any of them would make the check wrong, not simpler.
 */
final class GateMatrix
{
    public const SHOPS = ['withoutEither', 'withoutCommercial', 'withBoth', 'withoutUcp'];

    /**
     * @param array<string, ContainerBuilder>                                            $shops
     * @param array<string, list<array{id: string, needs: string, of: string, loadable: bool}>> $needs
     */
    private function __construct(
        public readonly array $shops,
        public readonly array $needs,
    ) {}

    /**
     * Builds all four in one pass, because `class_alias` is one-way: the two
     * SwagCommercial-absent containers must exist before the placeholders do,
     * and the two present ones after.
     *
     * Each shop's construction needs are snapshotted as it is built, while the
     * process's class table still matches that shop — see constructionNeeds().
     */
    public static function build(): self
    {
        $shops = [];
        $needs = [];

        $record = static function (string $shop, bool $ucp) use (&$shops, &$needs): void {
            $container = self::container($ucp);
            $shops[$shop] = $container;
            $needs[$shop] = self::constructionNeeds($container);
        };

        $record('withoutEither', false);
        $record('withoutCommercial', true);

        self::makeCommercialClassesAvailable();

        $record('withBoth', true);
        $record('withoutUcp', false);

        return new self($shops, $needs);
    }

    /**
     * The ids this shop's gates removed: every id services.php registers in any
     * configuration, minus the ids this shop has.
     *
     * Derived from the file on every run rather than listed by hand, so a
     * service added to either side of either gate is classified correctly
     * without anyone updating a test. An id referenced but in neither set
     * belongs to core or the SDK, which is not this gate's business.
     *
     * @return array<string, true>
     */
    public function removedIn(string $shop): array
    {
        $ids = static fn(ContainerBuilder $container): array => array_merge(
            array_keys($container->getDefinitions()),
            array_keys($container->getAliases()),
        );

        $everywhere = array_merge(...array_map($ids, array_values($this->shops)));

        return array_fill_keys(array_diff($everywhere, $ids($this->shops[$shop])), true);
    }

    /**
     * Every id this value depends on and cannot do without. Walks arguments,
     * properties, method calls and the factory, recursing through arrays and
     * through ArgumentInterface wrappers (service locators, tagged iterators).
     *
     * ignoreOnInvalid()/nullOnInvalid() references are skipped on purpose:
     * degrading to null is exactly what services.php uses them for.
     *
     * @return list<string>
     */
    public static function mandatoryReferences(mixed $value): array
    {
        if ($value instanceof Reference) {
            return (
                $value->getInvalidBehavior() === ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE
                    ? [(string) $value]
                    : []
            );
        }

        if ($value instanceof ArgumentInterface) {
            $value = $value->getValues();
        }

        if ($value instanceof Definition) {
            $value = [$value->getArguments(), $value->getProperties(), $value->getMethodCalls(), $value->getFactory()];
        }

        if (!\is_array($value)) {
            return [];
        }

        return array_merge(...array_map(self::mandatoryReferences(...), array_values($value)));
    }

    /**
     * What each autowired definition in this shop must be able to LOAD, read
     * the way AutowirePass reads it, and captured the moment the shop is built.
     *
     * The capture is the point, and it is the subtlety this whole fixture turns
     * on. `makeCommercialClassesAvailable()` aliases SwagCommercial's names into
     * existence process-wide, so by the time any assertion runs, `class_exists`
     * answers for the LAST shop built rather than the shop being examined. A
     * liveness check in the test method is therefore dead on exactly the
     * classes it exists to catch — confirmed by registering a SwagCommercial
     * class outside every gate in services.php and watching a live-`class_exists`
     * version of the check stay green. Only the id arithmetic, which no aliasing
     * touches, may be computed later.
     *
     * @return list<array{id: string, needs: string, of: string, loadable: bool}>
     */
    private static function constructionNeeds(ContainerBuilder $container): array
    {
        $needs = [];
        foreach ($container->getDefinitions() as $id => $definition) {
            // A factory-produced service has no constructor to autowire.
            if (!$definition->isAutowired() || $definition->getFactory() !== null) {
                continue;
            }

            // ResolveClassPass fills the class in from the id at compile time;
            // services.php registers everything as set(Foo::class).
            $class = $definition->getClass() ?? $id;
            $needs[] = ['id' => $id, 'needs' => $class, 'of' => 'its own class', 'loadable' => class_exists($class)];
            if (!class_exists($class)) {
                continue;
            }

            $constructor = (new \ReflectionClass($class))->getConstructor();
            if ($constructor === null) {
                continue;
            }

            foreach (self::autowiredTypes($constructor, $definition->getArguments()) as $of => $name) {
                $needs[] = [
                    'id' => $id,
                    'needs' => $name,
                    'of' => $of,
                    'loadable' => class_exists($name) || interface_exists($name),
                ];
            }
        }

        return $needs;
    }

    /**
     * The class-typed constructor parameters Symfony would have to autowire:
     * not filled by an explicit argument, and not optional — AutowirePass falls
     * back on a default rather than failing, so an optional parameter cannot
     * break a container.
     *
     * @param array<array-key, mixed> $arguments
     *
     * @return iterable<string, string> parameter label => type it needs
     */
    private static function autowiredTypes(\ReflectionMethod $constructor, array $arguments): iterable
    {
        foreach ($constructor->getParameters() as $position => $parameter) {
            $filled =
                \array_key_exists($position, $arguments) || \array_key_exists('$' . $parameter->getName(), $arguments);
            $optional = $parameter->isDefaultValueAvailable() || $parameter->isVariadic() || $parameter->allowsNull();
            $type = $parameter->getType();

            if ($filled || $optional || !$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            yield '$' . $parameter->getName() => $type->getName();
        }
    }

    /**
     * The UCP gate reads `kernel.bundles` and nothing else, so the bundle need
     * not be loadable here — only listed, exactly as the kernel would list it.
     */
    private static function container(bool $ucp): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'prod');
        $container->setParameter('kernel.bundles', $ucp ? ['UcpSdkBundle' => 'Ucp\Sdk\Symfony\UcpSdkBundle'] : []);
        (new MerchantQuoteAgentPlugin(active: true, basePath: \dirname(__DIR__, levels: 4)))->build($container);

        return $container;
    }

    /**
     * The gate only asks whether these names exist and never instantiates
     * anything behind them, so one placeholder under three names is enough.
     * Same trick as OrderHistoryLocatorConfigurationTest — and, like it, this
     * can only run in a process that is thrown away afterwards.
     */
    private static function makeCommercialClassesAvailable(): void
    {
        $placeholder = new class {};
        foreach ([
            CommercialAvailability::QUOTE_MANIPULATION,
            CommercialAvailability::QUOTE_COMMENTER,
            'Shopware\Commercial\Licensing\License',
        ] as $class) {
            class_alias($placeholder::class, $class);
        }
    }
}
