<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge\Commercial;

use Symfony\Component\DependencyInjection\Argument\ArgumentInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * What a service definition cannot be built without.
 *
 * Two questions, because Symfony answers them in two different places and a
 * check that asks only the first sees a minority of `services.php`:
 *
 * - **Written down** — `service(...)` references, which live on the definition
 *   and are readable the moment the file is loaded. mandatoryReferences().
 * - **Not written down** — constructor types Symfony resolves at compile,
 *   which is nearly everything, because `services.php` opens with
 *   `defaults()->autowire()`. inContainer().
 *
 * Split from GateMatrix, which builds the containers: reading a definition is
 * a different job from producing one, and the two together put one class over
 * the lint gate's method count.
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * Both rules aggregate per class against a threshold of 10. No method here is
 * individually complex — the count is the sum of the guard clauses that make
 * this a faithful reading of AutowirePass rather than an approximate one, and
 * each corresponds to a case Symfony itself special-cases (factories, explicit
 * arguments, optional parameters, builtin types). Dropping any of them would
 * make the check wrong, not simpler.
 */
final class DefinitionNeeds
{
    /**
     * Every id this value depends on and cannot do without. Walks arguments,
     * properties, method calls, the factory and the DECORATED SERVICE,
     * recursing through arrays and through ArgumentInterface wrappers (service
     * locators, tagged iterators).
     *
     * The decoration target is none of the other four and is easy to forget,
     * which is why it is called out here: a decorator whose target is gone
     * fails the whole container in DecoratorServicePass. Not hypothetical — it
     * is the one bug of this family the plugin has actually shipped (ADR 0001's
     * amendment, on the SDK's `RuntimeConfigurationResolverInterface`).
     *
     * ignoreOnInvalid()/nullOnInvalid() references are skipped on purpose:
     * degrading is exactly what `services.php` uses them for. Note the limit of
     * that — Symfony UNSETS an ignored argument rather than passing null, so a
     * degraded reference is only harmless where the matching parameter is
     * nullable AND defaulted. That holds throughout `services.php` today, and
     * nothing here enforces that it keeps holding.
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
            $value = [
                $value->getArguments(),
                $value->getProperties(),
                $value->getMethodCalls(),
                $value->getFactory(),
                self::decorationReference($value),
            ];
        }

        if (!\is_array($value)) {
            return [];
        }

        return array_merge(...array_map(self::mandatoryReferences(...), array_values($value)));
    }

    /**
     * What each autowired definition in a container must be able to LOAD.
     *
     * Call it the moment the container is built, never later. That is the
     * subtlety the whole fixture turns on: GateMatrix aliases SwagCommercial's
     * names into existence process-wide between builds, so by the time an
     * assertion runs, `class_exists` answers for the LAST shop built rather
     * than the shop being examined. A liveness check in a test method is
     * therefore dead on exactly the classes it exists to catch — confirmed by
     * registering a SwagCommercial class outside every gate in `services.php`
     * and watching a live-`class_exists` version of the check stay green. Only
     * id arithmetic, which no aliasing touches, may be computed later.
     *
     * @return list<array{id: string, needs: string, of: string, loadable: bool}>
     */
    public static function inContainer(ContainerBuilder $container): array
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
            $needs[] = [
                'id' => $id,
                'needs' => $class,
                'of' => 'its own class',
                'loadable' => class_exists($class) || interface_exists($class),
            ];
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
     * A definition's decoration target, as a Reference so the walker sees it.
     *
     * `getDecoratedService()` returns `[$id, $renamedInnerId, $priority,
     * $invalidBehavior]` — a bare string, not a Reference, which is exactly why
     * walking arguments alone misses it. Its own invalid behaviour carries
     * over: a decoration marked ignore-on-invalid is dropped, not fatal.
     */
    private static function decorationReference(Definition $definition): ?Reference
    {
        $decorated = $definition->getDecoratedService();

        return $decorated === null
            ? null
            : new Reference($decorated[0], $decorated[3] ?? ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE);
    }
}
