# Commercial Bundle Gate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A shop whose composer-installed SwagCommercial is deactivated boots, because the commercial gate asks `kernel.bundles` for `QuoteManagement` instead of asking the classpath.

**Architecture:** `CommercialAvailability` gains `isRegistered(?ContainerInterface)`, the commercial sibling of `UcpAvailability::isRegistered()`: bundle listed AND the existing class check. The three build-time gates (`services.php` twice, `AgentFacingRoutes`) switch to it; `isAvailableByClass()` goes private. The unit gate matrix gains a fifth shop, classes loadable but bundle absent, which is the failing test.

**Tech Stack:** PHP 8.3, Symfony DI, Shopware 6.7 plugin, PHPUnit, Mago.

**Spec:** `docs/superpowers/specs/2026-09-23-commercial-bundle-gate-design.md`

## Global Constraints

- Bundle key: `QuoteManagement` => `Shopware\Commercial\B2B\QuoteManagement\QuoteManagement` (verified on the 7.13 test shop and the 6.7.12 b2bseller shop).
- `declare(strict_types=1)`; Mago analyze at full strictness, no `mixed` escapes.
- Gate thresholds: cyclomatic complexity 10, nesting 4, parameters 5, ~400 lines/file.
- `isLicensed()` keeps its signature `public static function isLicensed(): bool`.
- Checks: `composer run format:check && composer run lint`, `composer run typecheck`, `composer run test`. `mago fmt` (not the check) to fix formatting.
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` and reference `#152`.

---

### Task 1: The probe

**Files:**
- Modify: `src/Bridge/Commercial/CommercialAvailability.php`
- Test: `tests/Unit/Bridge/Commercial/CommercialAvailabilityTest.php`

**Interfaces:**
- Produces: `CommercialAvailability::isRegistered(?\Symfony\Component\DependencyInjection\ContainerInterface $container): bool`

- [ ] **Step 1: Write the failing tests**

Add to `CommercialAvailabilityTest` (keep the two existing tests for now; Task 3 removes one), plus `use Symfony\Component\DependencyInjection\ContainerBuilder;`:

```php
    /**
     * The gate reads the bundle list, like UcpAvailability's, and keys on the
     * quote bundle rather than the SwagCommercial umbrella: the umbrella alone
     * says nothing about whether `quote.repository` exists.
     */
    public function testRegistrationNeedsTheQuoteBundle(): void
    {
        self::assertFalse(CommercialAvailability::isRegistered(null));

        $withoutParameter = new ContainerBuilder();
        self::assertFalse(CommercialAvailability::isRegistered($withoutParameter));

        $umbrellaOnly = new ContainerBuilder();
        $umbrellaOnly->setParameter('kernel.bundles', ['SwagCommercial' => 'Shopware\\Commercial\\SwagCommercial']);
        self::assertFalse(CommercialAvailability::isRegistered($umbrellaOnly));
    }

    /**
     * This suite runs without SwagCommercial's classes, so a listed bundle
     * must still answer false: the probe is bundle AND classes. The true case
     * needs the classes aliased, which is one-way, so GateMatrix's present
     * shops cover it in a thrown-away process.
     */
    public function testAListedBundleIsNotEnoughWithoutTheClasses(): void
    {
        $withBundle = new ContainerBuilder();
        $withBundle->setParameter('kernel.bundles', [
            'QuoteManagement' => 'Shopware\\Commercial\\B2B\\QuoteManagement\\QuoteManagement',
        ]);

        self::assertFalse(CommercialAvailability::isRegistered($withBundle));
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/Bridge/Commercial/CommercialAvailabilityTest.php`
Expected: 2 errors, `Call to undefined method ...CommercialAvailability::isRegistered()`.

- [ ] **Step 3: Implement**

In `CommercialAvailability.php` add `use Symfony\Component\DependencyInjection\ContainerInterface;` below the namespace, then, directly after `private const LICENSE_CLASS = ...;`:

```php
    /**
     * The bundle's name in `kernel.bundles`. SwagCommercial registers each
     * feature as a bundle of its own; this is the one that owns the quote
     * entities — so `quote.repository` — and every service the bridge
     * injects. Verified on the 7.13 test shop and the 6.7.12 b2bseller shop.
     */
    private const QUOTE_BUNDLE = 'QuoteManagement';

    /**
     * Stage one of ADR 0001's gate: is SwagCommercial's quote bundle in THIS
     * container, and does it carry the classes the bridge is written against?
     *
     * The bundle rather than the classpath, for the reason
     * UcpAvailability::isRegistered() records: SwagCommercial is
     * composer-installed into vendor/, so its classes stay loadable after a
     * merchant deactivates it. A class-only gate then registers services
     * against a `quote.repository` that left with the bundle — a container
     * that does not compile and a shop that does not boot (#152).
     *
     * The class check stays as the second half: a listed bundle without the
     * `@internal` classes is a SwagCommercial this bridge was not written for.
     */
    public static function isRegistered(?ContainerInterface $container): bool
    {
        // Null, or a ContainerBuilder assembled by hand in a test, has no
        // kernel parameters at all: absent, the safe answer.
        if ($container === null || !$container->hasParameter('kernel.bundles')) {
            return false;
        }

        $bundles = $container->getParameter('kernel.bundles');

        return \is_array($bundles) && \array_key_exists(self::QUOTE_BUNDLE, $bundles) && self::isAvailableByClass();
    }
```

- [ ] **Step 4: Run to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Bridge/Commercial/CommercialAvailabilityTest.php`
Expected: 4 tests, all pass.

Run: `composer run format:check && composer run lint && composer run typecheck`
Expected: clean.

- [ ] **Step 5: Commit**

```bash
git add src/Bridge/Commercial/CommercialAvailability.php tests/Unit/Bridge/Commercial/CommercialAvailabilityTest.php
git commit -m "feat(bridge): probe SwagCommercial's quote bundle in kernel.bundles

Refs #152

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: The service gate, and the shop that proves it

**Files:**
- Modify: `tests/Unit/Bridge/Commercial/GateMatrix.php`
- Modify: `tests/Unit/Bridge/Commercial/CommercialSurfaceConfigurationTest.php`
- Modify: `src/Resources/config/services.php:249` and `:583-591`
- Modify: `tests/Unit/Config/OrderHistoryLocatorConfigurationTest.php:30`

**Interfaces:**
- Consumes: `CommercialAvailability::isRegistered(?ContainerInterface): bool` (Task 1)
- Produces: `GateMatrix::SHOPS['vendoredButInactive']`; `GateMatrix` shops now carry `QuoteManagement` in `kernel.bundles` when their commercial gate is open.

- [ ] **Step 1: Give GateMatrix the fifth shop**

In `GateMatrix.php`:

1. Class docblock: `built four times` → `built five times`, and append to its first paragraph: `The fifth is #152's: SwagCommercial composer-installed but deactivated, so its classes load and its bundle is not in the kernel.`
2. Replace `SHOPS`:

```php
    /**
     * Which gates each shop has open: [SwagCommercial, UCP SDK bundle].
     *
     * `vendoredButInactive` has its commercial gate SHUT although its classes
     * load: that is the shop a merchant makes by deactivating a
     * composer-installed SwagCommercial (#152), and the gate must read it as
     * absent.
     */
    public const SHOPS = [
        'withoutEither' => [false, false],
        'withoutCommercial' => [false, true],
        'withBoth' => [true, true],
        'withoutUcp' => [true, false],
        'vendoredButInactive' => [false, true],
    ];
```

3. In `build()`: docblock `Builds all four in one pass` → `Builds all five in one pass`, and `and the two present ones after.` → `and the three whose classes load after.` Replace the closure and the `$record(...)` calls:

```php
        $record = static function (string $shop) use (&$shops, &$needs): void {
            [$commercial, $ucp] = self::SHOPS[$shop];
            $container = self::container($commercial, $ucp);
            $shops[$shop] = $container;
            $needs[$shop] = DefinitionNeeds::inContainer($container);
        };
```

```php
        $record('withoutEither');
        $record('withoutCommercial');

        self::makeCommercialClassesAvailable();

        $record('withBoth');
        $record('withoutUcp');
        // Classes loadable, bundle gone: a deactivated vendored SwagCommercial.
        $record('vendoredButInactive');
```

4. Replace `container()`:

```php
    /**
     * Both gates read `kernel.bundles`, so neither bundle need be loadable
     * here — only listed, exactly as the kernel would list it.
     */
    private static function container(bool $commercial, bool $ucp): ContainerBuilder
    {
        $bundles = [];
        if ($ucp) {
            $bundles['UcpSdkBundle'] = 'Ucp\Sdk\Symfony\UcpSdkBundle';
        }

        if ($commercial) {
            $bundles['QuoteManagement'] = 'Shopware\Commercial\B2B\QuoteManagement\QuoteManagement';
        }

        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'prod');
        $container->setParameter('kernel.bundles', $bundles);
        (new MerchantQuoteAgentPlugin(active: true, basePath: \dirname(__DIR__, levels: 4)))->build($container);

        return $container;
    }
```

`removedIn()` needs no change: it reads `SHOPS`, so the fifth shop's commercial foreign ids (`quote.repository`, `quote_line_item.repository`, `Shopware\Commercial\...`) count as removed.

- [ ] **Step 2: Point the tests at it**

In `CommercialSurfaceConfigurationTest.php`, add `use Symfony\Component\DependencyInjection\ContainerBuilder;`, add to `shopNames()` after the `withoutUcp` yield:

```php
        yield 'SwagCommercial vendored but deactivated' => ['vendoredButInactive'];
```

and add this test after `testTheBridgeAndItsConsumersAreAbsentWithoutSwagCommercial()`:

```php
    /**
     * #152, stated directly. A composer-installed SwagCommercial that a
     * merchant deactivates leaves its classes loadable and its bundle gone;
     * the gate must build exactly the shop it builds when SwagCommercial was
     * never there. The two dependency tests below state the consequence — no
     * `service('quote.repository')` left dangling — this states the cause.
     */
    public function testAVendoredButInactiveShopRegistersWhatAnAbsentOneDoes(): void
    {
        $shops = GateMatrix::build()->shops;
        $ids = static function (ContainerBuilder $container): array {
            $all = array_merge(array_keys($container->getDefinitions()), array_keys($container->getAliases()));
            sort($all);

            return $all;
        };

        self::assertSame($ids($shops['withoutCommercial']), $ids($shops['vendoredButInactive']));
    }
```

- [ ] **Step 3: Run to verify the new shop fails**

Run: `vendor/bin/phpunit tests/Unit/Bridge/Commercial/CommercialSurfaceConfigurationTest.php`
Expected: FAIL. `testAVendoredButInactiveShopRegistersWhatAnAbsentOneDoes` fails (the fifth shop has the whole bridge block), and `testNoServiceDependsOnOneItsGateRemoved` with data set `SwagCommercial vendored but deactivated` lists violations including `...QuoteWriter -> quote.repository`. The other four shops still pass. If the fifth shop passes here, stop: the fixture is not modelling the bug.

- [ ] **Step 4: Switch the two service gates**

`src/Resources/config/services.php:249`:

```php
        if (CommercialAvailability::isRegistered($container)) {
```

`src/Resources/config/services.php:583-591`, replace the comment and the condition:

```php
    // Stage one of ADR 0001's two-stage gate: is SwagCommercial's quote bundle
    // in this container? The bundle, not the classpath: SwagCommercial is
    // composer-installed, so its classes stay loadable after a merchant
    // deactivates it, and registering the block below against a
    // `quote.repository` that left with the bundle is a container that does
    // not compile (#152; see CommercialAvailability::isRegistered()). Absent
    // or inactive, nothing below is in the container and the quote capability
    // is simply not advertised (issue #1 owns that). Stage two, the license
    // toggle, is runtime and lives in QuoteGatewayFactory.
    if (!CommercialAvailability::isRegistered($container)) {
        return;
    }
```

- [ ] **Step 5: Run the matrix, then the whole unit suite**

Run: `vendor/bin/phpunit tests/Unit/Bridge/Commercial/CommercialSurfaceConfigurationTest.php`
Expected: all pass, five shops per data-provider test.

Run: `composer run test`
Expected: exactly one failure family, `OrderHistoryLocatorConfigurationTest` — it aliases the classes but lists no bundle, so the gate is now shut and `getAlias(BuyerQuoteGatewayInterface::class)` throws.

- [ ] **Step 6: Give that test its bundle**

In `tests/Unit/Config/OrderHistoryLocatorConfigurationTest.php`, after `$container->setParameter('kernel.environment', $kernelEnvironment);`:

```php
        $container->setParameter('kernel.bundles', [
            'QuoteManagement' => 'Shopware\\Commercial\\B2B\\QuoteManagement\\QuoteManagement',
        ]);
```

and change the comment in its `makeCommercialClassesAvailable()` from `Configuration only checks class existence;` to `The gate checks class existence alongside the bundle list;`.

- [ ] **Step 7: Verify**

Run: `composer run test`
Expected: all pass.

Run: `composer run format:check && composer run lint && composer run typecheck`
Expected: clean. If lint flags `GateMatrix` for a rule it did not before, report it; do not add a suppression without saying so.

- [ ] **Step 8: Commit**

```bash
git add src/Resources/config/services.php tests/Unit/Bridge/Commercial/GateMatrix.php tests/Unit/Bridge/Commercial/CommercialSurfaceConfigurationTest.php tests/Unit/Config/OrderHistoryLocatorConfigurationTest.php
git commit -m "fix(bridge): gate the commercial services on the quote bundle

A composer-installed SwagCommercial stays on the classpath after it is
deactivated, so the class-existence gate kept registering services
against a quote.repository that was gone and the container did not
compile. GateMatrix gains that shop as a fifth.

Fixes #152

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: The route gate, and retiring the old probe

**Files:**
- Modify: `src/Ucp/AgentFacingRoutes.php`
- Modify: `src/MerchantQuoteAgentPlugin.php:131-133`
- Modify: `src/Bridge/Commercial/CommercialAvailability.php` (visibility + docblocks)
- Modify: `tests/Unit/Bridge/Commercial/CommercialAvailabilityTest.php`
- Modify: `tests/Unit/Bridge/Commercial/CommercialSurfaceConfigurationTest.php` (docblock only)
- Modify: `tests/Integration/GatewayWiringTest.php:72-89`
- Modify (comments only): `src/Resources/config/services.php` (~620, ~697), `src/Bridge/QuoteGatewayFactory.php:14-21`, `src/Bridge/SwagCommercialBuyerQuoteGateway.php:32`, `src/Protocol/Http/A2cnDiscoveryController.php:42-44`
- Modify: `docs/adr/0001-runtime-plugin-dependencies.md` (the "left alone here" paragraph)

**Interfaces:**
- Consumes: `CommercialAvailability::isRegistered(?ContainerInterface): bool`
- Produces: `AgentFacingRoutes::import(RoutingConfigurator $routes, string $pluginPath, ?ContainerInterface $container): void`

No unit test covers `AgentFacingRoutes` today, and building a `RoutingConfigurator` that resolves attribute routes needs the kernel's loader chain. The route gate is covered by the on-shop check in Task 4 (404, not 500).

- [ ] **Step 1: Route gate**

`src/Ucp/AgentFacingRoutes.php`: add `use Symfony\Component\DependencyInjection\ContainerInterface;`. Replace the docblock's last paragraph (`Static: it holds nothing, ...`) with:

```php
 * Static: it holds nothing. The caller hands over its booted container for
 * the commercial gate to read — the same `kernel.bundles` services.php read
 * when it built that container — not to resolve collaborators from.
```

Change the signature and the gate:

```php
    /**
     * @param string $pluginPath the plugin root, i.e. Bundle::getPath()
     * @param ?ContainerInterface $container the booted container, i.e. Bundle::$container
     */
    public static function import(RoutingConfigurator $routes, string $pluginPath, ?ContainerInterface $container): void
```

```php
        if (!CommercialAvailability::isRegistered($container)) {
            return;
        }
```

`src/MerchantQuoteAgentPlugin.php` in `configureRoutes()`:

```php
            AgentFacingRoutes::import($routes, $this->getPath(), $this->container);
```

- [ ] **Step 2: Retire `isAvailableByClass()` from the public surface**

In `CommercialAvailability.php`:

- `public static function isAvailableByClass(): bool` → `private static function isAvailableByClass(): bool`.
- Class docblock: replace `class existence decides whether the gateway is built, the license toggle decides whether it can serve.` with `the quote bundle (with its classes) decides whether the gateway is built, the license toggle decides whether it can serve.`
- `isAvailableByClass()` docblock: append `Half of isRegistered(), and isLicensed()'s own guard; never a gate on its own — see isRegistered() on why.`

In `CommercialAvailabilityTest.php`: delete `testReportsUnavailableWhenSwagCommercialIsAbsent()` (it calls the now-private method; `testAListedBundleIsNotEnoughWithoutTheClasses` covers the same absence).

In `CommercialSurfaceConfigurationTest.php`, replace the docblock paragraph on `testTheEvidenceLayerBuildsWithoutSwagCommercial()` that starts `Does not itself assert` with:

```php
     * Does not itself assert `CommercialAvailability::isRegistered()` is
     * false: `CommercialAvailabilityTest` already covers the probe, and
     * GateMatrix has aliased the commercial classes into existence
     * process-wide by the time this method runs, so a second probe here would
     * only read back its own fixture.
```

- [ ] **Step 3: Integration test**

`tests/Integration/GatewayWiringTest.php`, replace the docblock and first assertion of `testALicensedShopIsAlsoAClassAvailableShop()` and rename it:

```php
    /**
     * Stage one of ADR 0001's gate — the quote bundle registered, with its
     * classes — decides whether `services.php` registers the bridge block at
     * all, so a shop where the license toggle is on but the gate is shut must
     * not exist. Cheap, and it pins the direction of the implication.
     */
    public function testALicensedShopIsAlsoARegisteredShop(): void
    {
        self::assertTrue(CommercialAvailability::isRegistered(static::getContainer()));
```

(keep the `isLicensed()` assertion below it unchanged).

- [ ] **Step 4: Comments that name the old guard**

- `services.php` ~620: `inside the isAvailableByClass()` → `inside the isRegistered()`.
- `services.php` ~697: replace `(No \`isAvailableByClass()\` guard here: the early return above already` / `means SwagCommercial's classes provably exist past this point.)` with `(No \`isRegistered()\` guard here: the early return above already means` / `SwagCommercial's quote bundle and classes are provably present past this point.)`
- `QuoteGatewayFactory.php` docblock: `Stage one, class existence, is NOT here.` → `Stage one, the quote bundle, is NOT here.`; `` `CommercialAvailability::isAvailableByClass()` `` → `` `CommercialAvailability::isRegistered()` ``. Keep `isLicensed() re-checks class existence anyway` — still true.
- `SwagCommercialBuyerQuoteGateway.php:32`: `registered when its classes exist (see CommercialAvailability)` → `registered when SwagCommercial's quote bundle is (see CommercialAvailability)`.
- `A2cnDiscoveryController.php:42-43`: `OUTSIDE the CommercialAvailability gate (see routes.php)` → `OUTSIDE the CommercialAvailability gate (see AgentFacingRoutes)`.

Then run: `grep -rn "isAvailableByClass" src tests docs/adr`
Expected: only `src/Bridge/Commercial/CommercialAvailability.php`.

- [ ] **Step 5: ADR**

In `docs/adr/0001-runtime-plugin-dependencies.md`, replace the paragraph beginning `` `CommercialAvailability` still uses `class_exists` and is left alone here`` with:

```markdown
`CommercialAvailability` followed on 2026-09-23 (#152), after the blind spot got
a measured consequence: deactivating a composer-installed SwagCommercial left
`services.php` registering plain `service('quote.repository')` references
against a bundle that was gone, and the shop did not boot. `isRegistered()`
looks for `QuoteManagement` in `kernel.bundles` — SwagCommercial registers each
feature as its own bundle, and that one owns the quote entities — AND keeps the
class check, which still answers whether this SwagCommercial carries the
`@internal` classes the bridge is written against. Both lanes (7.13 and 6.7.12)
list the bundle under that key. Its route gate in `AgentFacingRoutes` reads the
same probe from the booted container.
```

- [ ] **Step 6: Verify**

Run: `composer run format:check && composer run lint && composer run typecheck && composer run test`
Expected: all clean, all pass.

- [ ] **Step 7: Commit**

```bash
git add src tests docs/adr
git commit -m "refactor(bridge): read the route gate from the booted container

AgentFacingRoutes asks the same bundle probe services.php asked, so
the quote routes and their controllers cannot disagree. The class-only
probe goes private: it is half of the gate, never a gate on its own.

Refs #152

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: On the shop (controller, not a subagent)

Needs the local `merchant-quote-shop` container and the real deactivation, which ADR 0001's amendment says is the only place this family of bug has ever shown up.

- [ ] **Step 1: Sync and run integration**

```bash
./scripts/sync-to-shop.sh
composer run test:integration
```
Expected: `GatewayWiringTest::testALicensedShopIsAlsoARegisteredShop` passes. Known unrelated failures are listed in memory (configured-shop `PluginConfigTest`); compare against `main` before calling anything new.

- [ ] **Step 2: Deactivate SwagCommercial**

```bash
docker exec merchant-quote-shop sh -c 'cd /var/www/html && bin/console plugin:deactivate SwagCommercial && bin/console cache:clear'
```
Expected: both succeed. Then `curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8095/ucp/quotes` → `404`, and `curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8095/a2cn/sessions/x/acts` → `404` (the A2CN not-found controller), and the storefront home page → `200`.

- [ ] **Step 3: Reactivate**

```bash
docker exec merchant-quote-shop sh -c 'cd /var/www/html && bin/console plugin:activate SwagCommercial && bin/console cache:clear'
```
Expected: `GET /ucp/quotes` is no longer 404 (401/400 without credentials is fine), and `composer run test:integration` matches Step 1.

If Step 2 fails to boot, recover with `UPDATE plugin SET active = 1 WHERE name = 'SwagCommercial'` over PDO (`mysql:host=127.0.0.1;dbname=shopware`, root/root) and `rm -rf var/cache/*`, then report the error verbatim.
