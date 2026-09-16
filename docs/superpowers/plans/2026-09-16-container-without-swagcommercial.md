# Container Without SwagCommercial Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A unit test that builds `src/Resources/config/services.php` with SwagCommercial's classes unavailable and proves nothing left standing depends on something its gate removed.

**Architecture:** One new PHPUnit file builds the plugin's `ContainerBuilder` four times — the two gates crossed — using `MerchantQuoteAgentPlugin::build()` on a bare container, exactly as `tests/Unit/Ucp/UcpSurfaceConfigurationTest.php` already does. SwagCommercial presence is faked with `class_alias` onto three placeholder names (the trick `tests/Unit/Config/OrderHistoryLocatorConfigurationTest.php` already uses), so the two absent-SwagCommercial containers are built *before* the aliases exist. The union of ids across all four builds gives, per shop, the set its gates removed; the test then asserts no service in that shop has a mandatory dependency — explicit argument *or* autowired constructor parameter — on a removed id.

**Tech Stack:** PHP 8.3, PHPUnit 11, `symfony/dependency-injection` 7.4, `mago` (format/lint/analyze).

**Spec:** `docs/superpowers/specs/2026-09-16-container-without-swagcommercial-design.md`

## Global Constraints

- Branch: `test/79-container-compiles-without-commercial`. Do not push, do not open a PR, do not comment on the issue.
- Every commit message ends with `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.
- No production code changes. `src/` is read-only for this plan, except for the temporary scratch mutations in Task 4, which are reverted.
- The unit suite runs with no Shopware kernel, no database, and genuinely no SwagCommercial (ADR 0001 keeps it out of `composer.json`). Nothing in this plan may install it.
- `class_alias` is irreversible within a process. The test class MUST carry `#[RunTestsInSeparateProcesses]` and `#[PreserveGlobalState(false)]`, or `tests/Unit/Bridge/Commercial/CommercialAvailabilityTest.php` — which asserts `isAvailableByClass() === false` — breaks depending on test order.
- The test may not hard-code a list of which services live on which side of a gate. That list is derived from the file on every run. A hand-maintained list is the exact thing that rots.
- Gates: `composer run test`, `composer run quality`, `composer run test:integration`.
- `composer run quality` includes `mago fmt --check` and `mago analyze` over `src` **and** `tests`, so the new file must be formatted and fully type-annotated.

## File Structure

- **Create** `tests/Unit/Bridge/Commercial/CommercialSurfaceConfigurationTest.php` — the whole deliverable. Placed beside `CommercialAvailabilityTest.php`, mirroring `tests/Unit/Ucp/UcpSurfaceConfigurationTest.php`'s placement beside `UcpAvailabilityTest.php`.
- **Modify** `tests/Unit/Ucp/UcpSurfaceConfigurationTest.php` — one docblock line pointing at the new file, so someone reading either gate's test finds the other.

No other file changes.

---

### Task 1: The four containers, and the two named-service assertions

**Files:**
- Create: `tests/Unit/Bridge/Commercial/CommercialSurfaceConfigurationTest.php`

**Interfaces:**
- Consumes: `MerchantQuoteAgentPlugin\MerchantQuoteAgentPlugin::__construct(bool $active, string $basePath)` and its inherited `build(ContainerBuilder $container): void`; `MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability::QUOTE_MANIPULATION` and `::QUOTE_COMMENTER` (public string constants).
- Produces: a private static helper `shops(): array{withoutEither: ContainerBuilder, withoutCommercial: ContainerBuilder, withBoth: ContainerBuilder, withoutUcp: ContainerBuilder}` that Task 2 and Task 3 both call.

- [ ] **Step 1: Write the failing test**

Create the file with the four-shop builder and the two membership tests. `UcpSurfaceConfigurationTest` is the model for the build helper; `OrderHistoryLocatorConfigurationTest` is the model for `class_alias`.

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge\Commercial;

use MerchantQuoteAgentPlugin\Bridge\BuyerQuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsReader;
use MerchantQuoteAgentPlugin\MerchantQuoteAgentPlugin;
use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use MerchantQuoteAgentPlugin\Protocol\Emitter\SellerActEmitter;
use MerchantQuoteAgentPlugin\Protocol\Http\A2cnDiscoveryController;
use MerchantQuoteAgentPlugin\Protocol\Http\A2cnRecordsController;
use MerchantQuoteAgentPlugin\Protocol\Http\QuoteTerminalStateReader;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentityResolver;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnKeyStore;
use MerchantQuoteAgentPlugin\Protocol\Mandate\MandateSigner;
use MerchantQuoteAgentPlugin\Protocol\Mandate\SellerMandateFactory;
use MerchantQuoteAgentPlugin\Servicing\ServiceQuoteHandler;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The SwagCommercial half of the gate in `src/Resources/config/services.php`,
 * built from the real file — the commercial-side sibling of
 * tests/Unit/Ucp/UcpSurfaceConfigurationTest.php (#79).
 *
 * This suite runs with SwagCommercial genuinely absent: ADR 0001 keeps it out
 * of composer.json, so `class_exists` is false here with no help from anyone.
 * What has to be simulated is therefore PRESENCE, which is why the two absent
 * containers are built before `class_alias` runs and the two present ones
 * after. The aliases cannot be undone, hence the process isolation — without
 * it CommercialAvailabilityTest, which asserts the opposite, fails on order.
 *
 * Definitions, not a compiled container, for the reason
 * UcpSurfaceConfigurationTest already records: the file references core and SDK
 * ids nothing here provides, so compiling would fail for reasons that say
 * nothing about the gate. testNoServiceDependsOnOneItsGateRemoved() is what
 * stands in for the compile, and it is sharper — it sees only gate crossings.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CommercialSurfaceConfigurationTest extends TestCase
{
    /**
     * #79's list, restated against the gate these services actually sit
     * behind today. The issue calls them "unconditionally registered"; ADR
     * 0001's amendment has since moved the whole evidence layer behind the UCP
     * gate, so what is true of them — and what matters here — is that they need
     * no SwagCommercial.
     */
    public function testTheEvidenceLayerBuildsWithoutSwagCommercial(): void
    {
        $container = self::shops()['withoutCommercial'];

        self::assertFalse(CommercialAvailability::isAvailableByClass());
        foreach ([
            ProtocolHash::class,
            A2cnKeyStore::class,
            A2cnIdentityResolver::class,
            SellerMandateFactory::class,
            MandateSigner::class,
            A2cnDiscoveryController::class,
            QuoteAgentSettingsReader::class,
        ] as $id) {
            self::assertTrue($container->hasDefinition($id), $id . ' should survive without SwagCommercial');
        }
    }

    /**
     * The other half: absent rather than broken. Asserted against BOTH shops so
     * the test cannot pass by asserting nothing — every id named here is
     * present once SwagCommercial is.
     */
    public function testTheBridgeAndItsConsumersAreAbsentWithoutSwagCommercial(): void
    {
        $shops = self::shops();

        foreach ([
            SellerActEmitter::class,
            QuoteTerminalStateReader::class,
            A2cnRecordsController::class,
            ServiceQuoteHandler::class,
        ] as $id) {
            self::assertFalse(
                $shops['withoutCommercial']->hasDefinition($id),
                $id . ' needs SwagCommercial and must not be registered without it',
            );
            self::assertTrue($shops['withBoth']->hasDefinition($id), $id . ' should return with SwagCommercial');
        }

        foreach ([QuoteGatewayInterface::class, BuyerQuoteGatewayInterface::class] as $id) {
            self::assertFalse($shops['withoutCommercial']->has($id), $id . ' must not be registered without SwagCommercial');
            self::assertTrue($shops['withBoth']->has($id), $id . ' should return with SwagCommercial');
        }
    }

    /**
     * Both gates are booleans, so there are four shops. All four are built in
     * one pass because `class_alias` is one-way: the two SwagCommercial-absent
     * containers must exist before the placeholders do.
     *
     * @return array{
     *     withoutEither: ContainerBuilder,
     *     withoutCommercial: ContainerBuilder,
     *     withBoth: ContainerBuilder,
     *     withoutUcp: ContainerBuilder,
     * }
     */
    private static function shops(): array
    {
        $withoutEither = self::build(ucp: false);
        $withoutCommercial = self::build(ucp: true);

        self::makeCommercialClassesAvailable();

        return [
            'withoutEither' => $withoutEither,
            'withoutCommercial' => $withoutCommercial,
            'withBoth' => self::build(ucp: true),
            'withoutUcp' => self::build(ucp: false),
        ];
    }

    /**
     * The UCP gate reads `kernel.bundles` and nothing else, so the bundle need
     * not be loadable here — only listed, exactly as the kernel would list it.
     */
    private static function build(bool $ucp): ContainerBuilder
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
     * Same trick as OrderHistoryLocatorConfigurationTest, same process
     * isolation keeping it from leaking.
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
```

- [ ] **Step 2: Run test to verify it fails**

Before creating the file, confirm the suite is green so a later red is attributable:

```
vendor/bin/phpunit --testsuite unit
```

Then create the file and run only it:

```
vendor/bin/phpunit tests/Unit/Bridge/Commercial/CommercialSurfaceConfigurationTest.php
```

Expected at this point: **PASS**, both tests. This task has no implementation half — `services.php` is already correct, and the test's job is to pin that. The genuine red-then-green cycle for this file is Task 4, which mutates `services.php` and confirms each assertion catches it. If either test fails here, that is a real finding about `services.php`, not a broken test: stop and report it rather than adjusting the assertion to match.

- [ ] **Step 3: Confirm the fake classes do not leak**

Run the whole unit suite, which contains `CommercialAvailabilityTest::testReportsUnavailableWhenSwagCommercialIsAbsent`:

```
vendor/bin/phpunit --testsuite unit
```

Expected: PASS. If `CommercialAvailabilityTest` fails, the process-isolation attributes are missing or misspelled.

- [ ] **Step 4: Commit**

```bash
git add tests/Unit/Bridge/Commercial/CommercialSurfaceConfigurationTest.php
git commit -m "$(cat <<'EOF'
test(di): build the plugin's services without SwagCommercial (#79)

...

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: The dependency-closure check, explicit arguments

**Files:**
- Modify: `tests/Unit/Bridge/Commercial/CommercialSurfaceConfigurationTest.php`

**Interfaces:**
- Consumes: `shops()` from Task 1.
- Produces: `private static function removedIn(array $shops, string $shop): array<string, true>` returning the removed-id set as a lookup map, and `private static function mandatoryReferences(mixed $value): list<string>`. Task 3 calls `removedIn()`.

- [ ] **Step 1: Write the failing test**

Add to the class. The removed-id set is `union of all four shops' ids` minus `this shop's ids` — derived from the file, never listed by hand. An id referenced but in neither set is a core or SDK id and is not this gate's business.

```php
    /**
     * The check that replaces "the container compiles".
     *
     * For each of the four shops, the ids its gates removed are the union of
     * every id services.php registers anywhere, minus the ids this shop has —
     * computed from the file on every run, so a service added to either side of
     * either gate is classified without anyone updating this test. An id
     * referenced but in neither set belongs to core or the SDK, which is not
     * this gate's business.
     *
     * ignoreOnInvalid()/nullOnInvalid() references are skipped on purpose:
     * degrading to null is exactly what services.php uses them for.
     */
    #[DataProvider('shopNames')]
    public function testNoServiceDependsOnOneItsGateRemoved(string $shop): void
    {
        $shops = self::shops();
        $removed = self::removedIn($shops, $shop);
        $container = $shops[$shop];

        $violations = [];
        foreach ($container->getDefinitions() as $id => $definition) {
            foreach (self::mandatoryReferences($definition) as $reference) {
                if (isset($removed[$reference])) {
                    $violations[] = $id . ' -> ' . $reference;
                }
            }
        }

        foreach ($container->getAliases() as $id => $alias) {
            if (isset($removed[(string) $alias])) {
                $violations[] = 'alias ' . $id . ' -> ' . $alias;
            }
        }

        self::assertSame([], $violations, 'These services would not resolve on a ' . $shop . ' shop');
    }

    /** @return iterable<string, array{string}> */
    public static function shopNames(): iterable
    {
        yield 'neither SwagCommercial nor the UCP SDK bundle' => ['withoutEither'];
        yield 'the UCP SDK bundle but no SwagCommercial' => ['withoutCommercial'];
        yield 'SwagCommercial but no UCP SDK bundle' => ['withoutUcp'];
        yield 'both' => ['withBoth'];
    }

    /**
     * @param array<string, ContainerBuilder> $shops
     *
     * @return array<string, true>
     */
    private static function removedIn(array $shops, string $shop): array
    {
        $ids = static fn (ContainerBuilder $container): array => array_merge(
            array_keys($container->getDefinitions()),
            array_keys($container->getAliases()),
        );

        $everywhere = array_merge(...array_map($ids, array_values($shops)));

        return array_fill_keys(array_diff($everywhere, $ids($shops[$shop])), true);
    }

    /**
     * Every id this value depends on and cannot do without. Walks arguments,
     * properties, method calls and the factory, recursing through arrays and
     * through ArgumentInterface wrappers (service locators, tagged iterators).
     *
     * @return list<string>
     */
    private static function mandatoryReferences(mixed $value): array
    {
        if ($value instanceof Reference) {
            return $value->getInvalidBehavior() === ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE
                ? [(string) $value]
                : [];
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
```

Add the imports this needs:

```php
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DependencyInjection\Argument\ArgumentInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
```

- [ ] **Step 2: Run test to verify it passes on the real file**

```
vendor/bin/phpunit tests/Unit/Bridge/Commercial/CommercialSurfaceConfigurationTest.php
```

Expected: PASS, four data-provider cases. A failure here is a real defect in `services.php` — report it, do not weaken the assertion.

- [ ] **Step 3: Prove the walker actually walks**

This check is worthless if `mandatoryReferences()` silently returns nothing. Add a temporary `var_dump(count($violations))`-style probe, or run a one-off script that prints the total number of mandatory references found across `withoutCommercial`. Expected: a number in the dozens, not zero. Remove the probe afterwards.

- [ ] **Step 4: Commit**

```bash
git add tests/Unit/Bridge/Commercial/CommercialSurfaceConfigurationTest.php
git commit -m "$(cat <<'EOF'
test(di): no service may depend on one its gate removed (#79)

...

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: The dependency-closure check, autowired constructors

**Files:**
- Modify: `tests/Unit/Bridge/Commercial/CommercialSurfaceConfigurationTest.php`

**Interfaces:**
- Consumes: `shops()` and `removedIn()` from Tasks 1 and 2.
- Produces: nothing later tasks use.

**Why this task exists:** `services.php` opens with `$services->defaults()->autowire()`. A definition registered as `$services->set(Foo::class)` therefore carries **zero arguments** at load time — Task 2's walker sees nothing at all for it. Without this task the closure check inspects a minority of the graph, and most of a #76-shaped move goes through untouched. The rules below mirror `Symfony\Component\DependencyInjection\Compiler\AutowirePass`: it only fails on a parameter it cannot fill *and* that has no default.

- [ ] **Step 1: Write the failing test**

```php
    /**
     * The same rule for the dependencies nobody wrote down. services.php sets
     * `defaults()->autowire()`, so most definitions have no arguments at load
     * time and the walker above sees nothing for them — their dependencies are
     * constructor types Symfony resolves at compile. This reads them the way
     * AutowirePass would, and flags a class-typed parameter whose type this
     * shop's gates removed, or which is not loadable at all.
     *
     * The second arm is #79's other failure mode: an unconditional service
     * whose constructor names a class that only exists behind the gate.
     */
    #[DataProvider('shopNames')]
    public function testNoAutowiredServiceNeedsAClassItsGateRemoved(string $shop): void
    {
        $shops = self::shops();
        $removed = self::removedIn($shops, $shop);
        $container = $shops[$shop];

        $violations = [];
        foreach ($container->getDefinitions() as $id => $definition) {
            // A factory-produced service has no constructor to autowire.
            if (!$definition->isAutowired() || $definition->getFactory() !== null) {
                continue;
            }

            // ResolveClassPass fills the class in from the id at compile time;
            // services.php registers everything as set(Foo::class).
            $class = $definition->getClass() ?? $id;
            if (!class_exists($class)) {
                $violations[] = $id . ': class ' . $class . ' is not loadable';

                continue;
            }

            $constructor = (new \ReflectionClass($class))->getConstructor();
            if ($constructor === null) {
                continue;
            }

            $arguments = $definition->getArguments();
            foreach ($constructor->getParameters() as $position => $parameter) {
                // Filled explicitly, by position or by name: not autowired.
                if (\array_key_exists($position, $arguments) || \array_key_exists('$' . $parameter->getName(), $arguments)) {
                    continue;
                }

                // AutowirePass falls back rather than failing on these.
                if ($parameter->isDefaultValueAvailable() || $parameter->isVariadic() || $parameter->allowsNull()) {
                    continue;
                }

                $type = $parameter->getType();
                if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }

                $name = $type->getName();
                if ($container->has($name)) {
                    continue;
                }

                if (isset($removed[$name])) {
                    $violations[] = $id . ': autowires $' . $parameter->getName() . ' => ' . $name . ', which this shop has no';

                    continue;
                }

                if (!class_exists($name) && !interface_exists($name)) {
                    $violations[] = $id . ': autowires $' . $parameter->getName() . ' => ' . $name . ', which is not loadable';
                }
            }
        }

        self::assertSame([], $violations, 'These services would not autowire on a ' . $shop . ' shop');
    }
```

- [ ] **Step 2: Run test to verify it passes on the real file**

```
vendor/bin/phpunit tests/Unit/Bridge/Commercial/CommercialSurfaceConfigurationTest.php
```

Expected: PASS, eight cases total across the two data-provided tests.

- [ ] **Step 3: Prove this loop reaches the graph**

Same concern as Task 2 Step 3, and sharper here because three `continue`s could skip everything. Temporarily count the parameters that reach the final `class_exists` arm across the `withoutCommercial` shop. Expected: dozens. Remove the probe.

- [ ] **Step 4: Point the UCP-side test at this one**

In `tests/Unit/Ucp/UcpSurfaceConfigurationTest.php`, add one line to the class docblock:

```
 * The SwagCommercial half of the same file is
 * tests/Unit/Bridge/Commercial/CommercialSurfaceConfigurationTest.php, which
 * also checks that nothing either gate leaves standing depends on something
 * the other removed.
```

- [ ] **Step 5: Commit**

```bash
git add tests/Unit/Bridge/Commercial/CommercialSurfaceConfigurationTest.php tests/Unit/Ucp/UcpSurfaceConfigurationTest.php
git commit -m "$(cat <<'EOF'
test(di): read the autowired dependencies too (#79)

...

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Prove it bites

**Files:**
- Temporarily modify, then revert: `src/Resources/config/services.php`

**Interfaces:**
- Consumes: the finished test.
- Produces: the evidence paragraph for the final report.

**This task is the deliverable.** A test that would not have caught the #76 moves is not worth landing. Each mutation below is applied to the working tree, the test is run, the named case is confirmed red with a legible message, and the file is restored with `git checkout -- src/Resources/config/services.php`. Nothing from this task is committed.

- [ ] **Step 1: Mutation A — an autowired gated service moved above the gate**

Move `$services->set(ServicingPreflight::class);` from below the `if (!CommercialAvailability::isAvailableByClass()) { return; }` early return to just above it.

Run:

```
vendor/bin/phpunit tests/Unit/Bridge/Commercial/CommercialSurfaceConfigurationTest.php
```

Expected: `testNoAutowiredServiceNeedsAClassItsGateRemoved` RED for the two SwagCommercial-absent shops, naming `ServicingPreflight` and the removed type it autowires.

Record the exact failure message, then:

```bash
git checkout -- src/Resources/config/services.php
```

- [ ] **Step 2: Mutation B — an ungated service given an explicit reference into the gated block**

This is #76's exact shape. Change the ungated `QuoteAgentSettingsReader` registration to take a gated collaborator explicitly:

```php
    $services->set(QuoteAgentSettingsReader::class)
        ->arg('$envApiKey', '%env(default::MQA_LLM_API_KEY)%')
        ->arg('$strategies', service(StrategyResolver::class))
        ->arg('$unused', service(QuoteGatewayInterface::class));
```

(The argument name need not exist on the constructor — the walker reads the definition's references, not the class. If `mago analyze` is run at this point it will object; that is fine, this state is never committed.)

Run the test. Expected: `testNoServiceDependsOnOneItsGateRemoved` RED for both SwagCommercial-absent shops, naming `QuoteAgentSettingsReader -> MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface`.

Record the message, then `git checkout -- src/Resources/config/services.php`.

- [ ] **Step 3: Mutation C — an ungated definition whose class is not loadable**

Register a SwagCommercial class by name, outside every gate, near the top of the closure:

```php
    $services->set(CommercialAvailability::QUOTE_MANIPULATION);
```

Run the test. Expected: `testNoAutowiredServiceNeedsAClassItsGateRemoved` RED for the two SwagCommercial-absent shops with "class ... is not loadable".

Record the message, then `git checkout -- src/Resources/config/services.php`.

- [ ] **Step 4: Mutation D — the UCP gate, to show the check is not commercial-only**

Move `$services->set(ChainMirror::class);` out of the `if (UcpAvailability::isRegistered($container))` block to just below it, so it is registered on a shop with no UCP surface while its dependencies stay gated.

Run the test. Expected: RED for `withoutEither` and `withoutUcp`.

Record the message, then `git checkout -- src/Resources/config/services.php`.

- [ ] **Step 5: Confirm the tree is clean and the suite is green**

```
/usr/bin/git status --porcelain src/
vendor/bin/phpunit --testsuite unit
```

Expected: no output from the first (the mutations are all reverted), PASS from the second.

If any mutation failed to turn the test red, that is a hole in the test. Fix the test, re-run every mutation from Step 1, and only then continue.

---

### Task 5: Gates

**Files:** none.

- [ ] **Step 1: Unit suite**

```
composer run test
```

Expected: PASS.

- [ ] **Step 2: Quality**

```
composer run quality
```

Expected: PASS. `mago fmt --check` and `mago analyze` cover `tests/`, so fix formatting with `composer run format` and type errors in the new file rather than suppressing them. `mago analyze` runs at full strictness: the docblock `@return` shapes in Tasks 1 and 2 are load-bearing, not decoration.

- [ ] **Step 3: Integration suite**

```
composer run test:integration
```

Expected: PASS except `PluginConfigTest::testInstallTimeDefaultsArePersistedWithNativeTypes`, which may be red for an unrelated reason (a migration the shared shop has not run). `merchant-quote-shop` is shared with three other agents — do not mutate it, and re-run once before concluding any other failure is real. This plan changes no production code, so any other red is either pre-existing or another agent's sync; verify against the base commit checked out detached (`/usr/bin/git checkout --detach <base>`), never with `git stash`, which is shared across worktrees.

- [ ] **Step 4: Commit any fixes the gates required**

```bash
git add -A tests/
git commit -m "$(cat <<'EOF'
style(test): satisfy the quality gate

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Self-Review

**Spec coverage.**

| Spec section | Task |
|---|---|
| Four shops, `class_alias` ordering, process isolation | 1 |
| Assertion 1 — gated block absent, not broken | 1 |
| Assertion 2 — ungated block still builds (#79's six, plus config) | 1 |
| Assertion 3 — explicit mandatory references | 2 |
| Assertion 3 — autowired constructor parameters, not-loadable arm | 3 |
| Removed-id set derived from the file, never hand-listed | 2 (`removedIn`) |
| Evidence it bites — three mutations | 4 (four: A–C from the spec, D added for the UCP gate) |
| "Not covered" — route gate, the probe itself, real compile | documented in the spec; restated in the final report |
| Gates | 5 |

No spec section is unimplemented. The spec's `isAvailableByClass()` finding is a report item, not a code change, by Global Constraint "no production code changes".

**Placeholder scan.** The `...` inside the three commit-message heredocs are the one deliberate gap: the body is written at commit time from what the task actually did. Every code step carries real code. No "TBD", no "handle edge cases".

**Type consistency.** `shops()` returns the four-key shape in Task 1 and is indexed with exactly those keys in Tasks 1, 2 and 3. `removedIn(array $shops, string $shop)` is defined in Task 2 and called with the same signature in Task 3. `mandatoryReferences(mixed $value): list<string>` is defined and used only in Task 2. `shopNames()` is defined once in Task 2 and reused by the `#[DataProvider]` in Task 3 — PHPUnit resolves it on the same class, so this is correct, but it does mean Task 3 cannot land before Task 2.
