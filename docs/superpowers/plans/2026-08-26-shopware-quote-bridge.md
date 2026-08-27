# Shopware Quote Bridge Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the one module that talks to SwagCommercial's B2B quote services, behind a single `QuoteGatewayInterface`, so nothing else in the plugin ever references a SwagCommercial class.

**Architecture:** `src/Bridge/` holds the interface, pure-PHP value objects under `Bridge\Data\`, two thin adapters that confine untyped SwagCommercial objects, and one `SwagCommercialQuoteGateway` that is the only class permitted to touch them. A factory does the `class_exists` + license-toggle gate. Writes go through Shopware's generic DAL where possible and through SwagCommercial services only where they must; the integration test suite runs against a live shop and *is* the issue's spike.

**Tech Stack:** PHP 8.3, Shopware 6.7 (`shopware/core` is a real Composer dep), SwagCommercial 7.13 (runtime-only, never a Composer dep), PHPUnit 11, mago (fmt/lint/analyze), jscpd.

**Spec:** `docs/superpowers/specs/2026-08-26-shopware-quote-bridge-design.md`

## Global Constraints

- **Nothing outside `src/Bridge/` may reference a SwagCommercial class.** Inside the bridge, only the two adapters and the factory may.
- **SwagCommercial class names are string literals, never `::class`** — the classes need not be loadable. Per ADR 0001.
- **Never add `shopware/commercial` or `shopware/agentic-commerce` to composer.json.** Per ADR 0001. `shopware/core` is already a dep and its types *do* typecheck.
- **License toggle is `QUOTE_MANAGEMENT-6302947`** (the one every commercial *service* checks internally), **not `QUOTE_MANAGEMENT-8702512`** (which only guards the Admin API *route* we do not use).
- **A repriced line must also carry `customFields['quote_custom_offer_price'] === true`**, or the next `recalculate()` re-prices it from the catalog and silently discards our `priceDefinition`.
- **`customFields` writes must shallow-merge top-level keys, never replace** — the A2CN act chain stores one act per top-level key so buyer and seller appends do not collide.
- **Quality gates, all must pass:** `composer run format:check`, `lint`, `typecheck`, `quality:filesize`, `quality:dupes` (<6%), `quality:depcheck`, `quality:security`, plus `vendor/bin/phpunit`.
- **mago thresholds:** max 5 constructor parameters, max 10 cyclomatic complexity per class, ~400 lines per file, one class-like per file, `#[Override]` required on every interface implementation, `@throws` docblock required for any uncaught throwable.
- **`mago.toml` scopes fmt/lint to `src/` only.** Test files must be formatted explicitly with `vendor/bin/mago fmt tests/` — the pre-commit hook checks all staged files and will reject otherwise.
- **Integration tests never run in CI.** `.github/workflows/quality-gate.yml` runs no tests at all today; do not add any. SwagCommercial is licensed and only present on the local shop.

## Environment facts (verified 2026-08-26)

- Live shop: docker container `shopware-trunk` (`dockware/shopware:dev-main`), port 8090, healthy. `SwagCommercial 7.13.0` and `SwagAgenticCommerce 1.1.1` both installed and active.
- `/var/www/html` in that container is the named volume `agentic-supplier-gateway_shopware-trunk-html` — **not** a bind mount, so plugin source must be copied in. (`quote-shop-paas` is a different project and does not back this container.)
- The container has `/var/www/html/vendor/bin/phpunit`, `vendor/shopware/core/TestBootstrapper.php`, and a working `DATABASE_URL`.
- SwagCommercial source for reference reading: `/Users/sebastian/projects/SwagCommercial/src/B2B/QuoteManagement/`.
- `mago analyze` **does** resolve Shopware core types (it rejects a non-existent method on `EntityRepository`). It flags a method call on a bare `object` as `analysis:ambiguous-object-method-access` (a warning) and on `?object` additionally as `possible-method-access-on-null` (an error). Verified fix: adapters hold a **non-nullable** `object` and carry `/** @mago-expect analysis:ambiguous-object-method-access */` at the call site; the null check lives in the factory.

---

### Task 1: Integration test harness (the spike's foundation)

Nothing downstream can be verified until a test can boot the Shopware kernel with SwagCommercial present and pull a commercial service out of the container. Prove that first.

**Files:**
- Create: `phpunit.integration.xml.dist`
- Create: `tests/Integration/bootstrap.php`
- Create: `tests/Integration/IntegrationTestCase.php`
- Create: `tests/Integration/HarnessSmokeTest.php`
- Create: `scripts/sync-to-shop.sh`
- Modify: `composer.json` (scripts section)

**Interfaces:**
- Consumes: nothing.
- Produces: `MerchantQuoteAgentPlugin\Tests\Integration\IntegrationTestCase` — abstract base giving subclasses `static::getContainer(): ContainerInterface` (from Shopware's `KernelTestBehaviour`) and `static::commercialService(string $id): object`. Every later integration test extends it.

- [ ] **Step 1: Write the sync script**

`scripts/sync-to-shop.sh`:

```bash
#!/usr/bin/env bash
# Copy this plugin into the live shop container. /var/www/html is a named
# volume, not a bind mount, so there is nothing to symlink — we push files.
set -euo pipefail

CONTAINER="${SHOP_CONTAINER:-shopware-trunk}"
DEST="/var/www/html/custom/plugins/MerchantQuoteAgentPlugin"

docker exec "$CONTAINER" mkdir -p "$DEST"
tar --exclude=vendor --exclude=.git --exclude=report --exclude=node_modules -cf - . \
  | docker exec -i "$CONTAINER" tar -xf - -C "$DEST"

docker exec "$CONTAINER" php bin/console plugin:refresh
docker exec "$CONTAINER" php bin/console plugin:install --activate MerchantQuoteAgentPlugin || true
echo "synced to $CONTAINER:$DEST"
```

Then `chmod +x scripts/sync-to-shop.sh`.

- [ ] **Step 2: Write the bootstrap**

`tests/Integration/bootstrap.php`. `TestBootstrapper`'s API is verified: `setProjectDir`, `setLoadEnvFile`, `setEnableCommercial`, `addCallingPlugin`, `bootstrap`.

```php
<?php

declare(strict_types=1);

use Shopware\Core\TestBootstrapper;

$shopRoot = getenv('SHOPWARE_ROOT') ?: '/var/www/html';

/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require $shopRoot . '/vendor/autoload.php';
$loader->addPsr4('MerchantQuoteAgentPlugin\\', __DIR__ . '/../../src/');
$loader->addPsr4('MerchantQuoteAgentPlugin\\Tests\\', __DIR__ . '/../');

(new TestBootstrapper())
    ->setProjectDir($shopRoot)
    ->setClassLoader($loader)
    ->setLoadEnvFile(true)
    ->setEnableCommercial(true)
    ->addCallingPlugin(__DIR__ . '/../../composer.json')
    ->bootstrap();
```

- [ ] **Step 3: Write the PHPUnit config**

`phpunit.integration.xml.dist` — a **separate** file so bare `vendor/bin/phpunit` stays unit-only and never tries to reach a shop.

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/Integration/bootstrap.php"
         colors="true"
         failOnWarning="true"
         failOnRisky="true">
    <testsuites>
        <!-- Runs only inside the shop container. Never wired into CI:
             SwagCommercial is licensed and absent from public runners. -->
        <testsuite name="integration">
            <directory>tests/Integration</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 4: Write the base test case**

`tests/Integration/IntegrationTestCase.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\DatabaseTransactionBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;

/**
 * Base for every bridge integration test. Each test runs in a transaction that
 * is rolled back, so tests may write freely to the live shop's database.
 */
abstract class IntegrationTestCase extends TestCase
{
    use KernelTestBehaviour;
    use DatabaseTransactionBehaviour;

    /**
     * Fetch a service by raw id. Commercial services are not typed here on
     * purpose — the adapters are what give them a type.
     */
    protected static function commercialService(string $id): object
    {
        $service = static::getContainer()->get($id);
        self::assertIsObject($service, sprintf('Service "%s" is not available.', $id));

        return $service;
    }
}
```

- [ ] **Step 5: Write the failing smoke test**

`tests/Integration/HarnessSmokeTest.php`. This asserts the three things the whole plan rests on: the kernel boots, SwagCommercial's services are in the container, and the *service-level* license toggle is active.

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

final class HarnessSmokeTest extends IntegrationTestCase
{
    public function testCommercialQuoteServicesAreResolvable(): void
    {
        self::commercialService('Shopware\Commercial\B2B\QuoteManagement\Domain\Admin\QuoteManipulation');
        self::commercialService('Shopware\Commercial\B2B\QuoteManagement\Domain\Comment\QuoteCommenter');
        self::commercialService('Shopware\Commercial\B2B\QuoteManagement\Domain\Recalculation\QuoteCalculator');
        self::commercialService(
            'Shopware\Commercial\B2B\QuoteManagement\Domain\SalesChannelContextRestorer\SalesChannelContextRestorer'
        );
    }

    public function testServiceLevelLicenseToggleIsActive(): void
    {
        /** @var callable(string): (string|bool|int) $get */
        $get = ['Shopware\Commercial\Licensing\License', 'get'];

        self::assertNotFalse(
            $get('QUOTE_MANAGEMENT-6302947'),
            'The service-level toggle is what every commercial quote service checks on entry.'
        );
    }
}
```

- [ ] **Step 6: Add composer scripts**

In `composer.json` `scripts`, add:

```json
"test": "phpunit",
"test:integration": "scripts/sync-to-shop.sh && docker exec -w /var/www/html/custom/plugins/MerchantQuoteAgentPlugin shopware-trunk /var/www/html/vendor/bin/phpunit -c phpunit.integration.xml.dist"
```

- [ ] **Step 7: Run it and iterate until green**

Run: `composer run test:integration`

Expected first run: failure. Likely causes and fixes, in order of likelihood:
- Plugin not recognised by `plugin:refresh` → check `composer.json`'s `extra.shopware-plugin-class` matches `src/MerchantQuoteAgentPlugin.php`.
- `addCallingPlugin` cannot resolve the plugin → replace with `->addActivePlugins('MerchantQuoteAgentPlugin')`.
- Service id not found → the container may only expose these as private services. If so, resolve via the concrete class-name id as written; if still absent, the fallback is to fetch them through `getContainer()->get()` on the alias SwagCommercial registers — read `/Users/sebastian/projects/SwagCommercial/src/B2B/QuoteManagement/DependencyInjection/*.xml` to find the real id and use that. **Record the working ids in the test's docblock**; later tasks depend on them.

Do not proceed to Task 2 until both smoke tests pass. If the license toggle test fails, stop and report — the whole design assumes a licensed shop.

- [ ] **Step 8: Verify the unit suite is untouched**

Run: `vendor/bin/phpunit`
Expected: the 64 existing unit tests pass, and **no** integration test runs.

- [ ] **Step 9: Format, lint, commit**

```bash
vendor/bin/mago fmt tests/ && composer run format:check && composer run lint
git add phpunit.integration.xml.dist tests/Integration scripts/sync-to-shop.sh composer.json
git commit -m "test: integration harness against the live SwagCommercial shop"
```

---

### Task 2: `Bridge\Data` value objects

Pure PHP, zero Shopware. Unit-testable, and the vocabulary every later task uses.

**Files:**
- Create: `src/Bridge/Data/QuoteVersion.php`, `QuoteTransition.php`, `DiscountType.php`, `Discount.php`, `QuoteRevision.php`, `QuoteLineItemChange.php`, `QuoteUpdate.php`, `QuoteLineIdentity.php`, `QuoteLineSnapshot.php`, `QuoteComment.php`, `QuoteIdentity.php`, `QuoteTotals.php`, `QuoteLifecycle.php`, `QuoteContent.php`, `QuoteSnapshot.php`
- Create: `src/Bridge/QuoteNotFoundException.php`, `src/Bridge/QuoteRevisionMismatch.php`
- Test: `tests/Unit/Bridge/Data/QuoteUpdateTest.php`, `tests/Unit/Bridge/Data/QuoteLineItemChangeTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: every type below. Note the grouping — `QuoteSnapshot` takes exactly 5 constructor params to stay inside the gate, so its fields are grouped into `QuoteIdentity`, `QuoteRevision`, `QuoteTotals`, `QuoteLifecycle`, `QuoteContent`.

- [ ] **Step 1: Write the enums and simple value objects**

One class-like per file. `QuoteVersion` stays *pure* — it must not name SwagCommercial's snapshot constant; the gateway maps it.

`src/Bridge/Data/QuoteVersion.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data;

/**
 * Which DAL version lane to read. `Snapshot` is SwagCommercial's fixed
 * "what the counterparty last saw" lane; the gateway maps these onto the
 * actual version ids, since naming them here would leak a commercial constant.
 */
enum QuoteVersion
{
    case Live;
    case Snapshot;
}
```

`src/Bridge/Data/QuoteTransition.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data;

/** The quote state-machine actions an agent servicing pass drives. */
enum QuoteTransition: string
{
    case Process = 'process';
    case Sent = 'sent';
    case Decline = 'decline';
    case RequestChange = 'request_change';
}
```

`src/Bridge/Data/DiscountType.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data;

enum DiscountType: string
{
    case Percentage = 'percentage';
    case Absolute = 'absolute';
}
```

`src/Bridge/Data/Discount.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data;

final readonly class Discount
{
    public function __construct(
        public DiscountType $type,
        public float $value,
    ) {}
}
```

`src/Bridge/Data/QuoteRevision.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data;

/**
 * What the quote looked like, version-wise, when we read it. Pass one back
 * into a write to have the gateway refuse if the quote moved meanwhile.
 */
final readonly class QuoteRevision
{
    public function __construct(
        public string $versionId,
        public \DateTimeImmutable $updatedAt,
    ) {}

    public function matches(self $other): bool
    {
        return $this->versionId === $other->versionId
            && $this->updatedAt->getTimestamp() === $other->updatedAt->getTimestamp();
    }
}
```

- [ ] **Step 2: Write the failing test for the write-request objects**

`tests/Unit/Bridge/Data/QuoteLineItemChangeTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge\Data;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineItemChange;
use PHPUnit\Framework\TestCase;

final class QuoteLineItemChangeTest extends TestCase
{
    public function testNullFieldsMeanUntouched(): void
    {
        $change = new QuoteLineItemChange(lineItemId: 'l1', unitPriceNet: 90.0);

        self::assertSame('l1', $change->lineItemId);
        self::assertSame(90.0, $change->unitPriceNet);
        self::assertNull($change->quantity);
        self::assertNull($change->remove);
        self::assertTrue($change->touchesPrice());
    }

    public function testRemovalDoesNotTouchPrice(): void
    {
        $change = new QuoteLineItemChange(lineItemId: 'l1', remove: true);

        self::assertFalse($change->touchesPrice());
        self::assertTrue($change->isRemoval());
    }
}
```

`tests/Unit/Bridge/Data/QuoteUpdateTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge\Data;

use MerchantQuoteAgentPlugin\Bridge\Data\Discount;
use MerchantQuoteAgentPlugin\Bridge\Data\DiscountType;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use PHPUnit\Framework\TestCase;

final class QuoteUpdateTest extends TestCase
{
    public function testEmptyUpdateTouchesNothing(): void
    {
        self::assertTrue((new QuoteUpdate())->isEmpty());
    }

    public function testDiscountOnlyUpdateIsNotEmpty(): void
    {
        $update = new QuoteUpdate(discount: new Discount(DiscountType::Percentage, 10.0));

        self::assertFalse($update->isEmpty());
        self::assertNull($update->expiresAt);
        self::assertNull($update->customFields);
    }
}
```

- [ ] **Step 3: Run to verify failure**

Run: `vendor/bin/phpunit --filter "QuoteLineItemChangeTest|QuoteUpdateTest"`
Expected: FAIL — `Class "…\QuoteLineItemChange" not found`.

- [ ] **Step 4: Implement the write-request objects**

`src/Bridge/Data/QuoteLineItemChange.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data;

/**
 * One line item's requested change. A null field means "leave it alone", so a
 * batch can reprice one line, requantify another and remove a third.
 */
final readonly class QuoteLineItemChange
{
    public function __construct(
        public string $lineItemId,
        public ?float $unitPriceNet = null,
        public ?int $quantity = null,
        public ?bool $remove = null,
    ) {}

    public function touchesPrice(): bool
    {
        return $this->unitPriceNet !== null && !$this->isRemoval();
    }

    public function isRemoval(): bool
    {
        return $this->remove === true;
    }
}
```

`src/Bridge/Data/QuoteUpdate.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data;

/**
 * Quote-level fields to change. A null field means "leave it alone".
 *
 * `customFields` is SHALLOW-MERGED by the gateway, never replaced: the A2CN act
 * chain stores one act per top-level key so buyer and seller appends do not
 * collide, and a replacing write would destroy the counterparty's acts.
 */
final readonly class QuoteUpdate
{
    /** @param array<string, mixed>|null $customFields */
    public function __construct(
        public ?Discount $discount = null,
        public ?\DateTimeImmutable $expiresAt = null,
        public ?array $customFields = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->discount === null && $this->expiresAt === null && $this->customFields === null;
    }
}
```

- [ ] **Step 5: Run to verify pass**

Run: `vendor/bin/phpunit --filter "QuoteLineItemChangeTest|QuoteUpdateTest"`
Expected: PASS (4 tests).

- [ ] **Step 6: Write the read-model objects**

These carry no behaviour, so no unit tests of their own — Task 4's integration test is what proves they hold real data. Grouped to keep `QuoteSnapshot` at 5 constructor params.

`src/Bridge/Data/QuoteLineIdentity.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data;

final readonly class QuoteLineIdentity
{
    public function __construct(
        public string $lineItemId,
        public ?string $label = null,
        public ?string $productId = null,
    ) {}
}
```

`src/Bridge/Data/QuoteLineSnapshot.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data;

/**
 * `unitPriceNet` is deliberately unconstrained in sign: Shopware-generated
 * lines (the quote-discount line) are legitimately negative.
 */
final readonly class QuoteLineSnapshot
{
    public function __construct(
        public QuoteLineIdentity $identity,
        public int $quantity,
        public float $unitPriceNet,
        public float $totalNet,
        public ?float $requestedUnitPrice = null,
    ) {}
}
```

`src/Bridge/Data/QuoteComment.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data;

/**
 * `createdById` / `customerId` are how a reader tells an agent-authored comment
 * from a buyer's. Task 8 records what they actually contain for our own writes.
 */
final readonly class QuoteComment
{
    public function __construct(
        public string $comment,
        public ?string $lineItemId = null,
        public ?string $createdById = null,
        public ?string $customerId = null,
        public ?\DateTimeImmutable $createdAt = null,
    ) {}
}
```

`src/Bridge/Data/QuoteIdentity.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data;

final readonly class QuoteIdentity
{
    public function __construct(
        public string $quoteId,
        public string $quoteNumber,
        public string $currencyIso,
    ) {}
}
```

`src/Bridge/Data/QuoteTotals.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data;

final readonly class QuoteTotals
{
    public function __construct(
        public float $totalNet,
        public ?Discount $discount = null,
    ) {}
}
```

`src/Bridge/Data/QuoteLifecycle.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data;

final readonly class QuoteLifecycle
{
    /** @param array<string, mixed> $customFields */
    public function __construct(
        public string $stateTechnicalName,
        public ?\DateTimeImmutable $expiresAt = null,
        public array $customFields = [],
    ) {}
}
```

`src/Bridge/Data/QuoteContent.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data;

final readonly class QuoteContent
{
    /**
     * @param list<QuoteLineSnapshot> $lines
     * @param list<QuoteComment> $comments
     */
    public function __construct(
        public array $lines = [],
        public array $comments = [],
    ) {}
}
```

`src/Bridge/Data/QuoteSnapshot.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data;

/**
 * The bridge's own full-fidelity read model — never a SwagCommercial
 * QuoteEntity, and deliberately NOT Policy\Data\QuoteSnapshot, which is
 * trimmed to what negotiation-core reads. Servicing (issue #4) adapts between
 * the two.
 */
final readonly class QuoteSnapshot
{
    public function __construct(
        public QuoteIdentity $identity,
        public QuoteRevision $revision,
        public QuoteTotals $totals,
        public QuoteLifecycle $lifecycle,
        public QuoteContent $content,
    ) {}
}
```

- [ ] **Step 7: Write the exceptions**

`src/Bridge/QuoteNotFoundException.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

final class QuoteNotFoundException extends \RuntimeException
{
    public static function forId(string $quoteId): self
    {
        return new self(sprintf('Quote "%s" was not found.', $quoteId));
    }
}
```

`src/Bridge/QuoteRevisionMismatch.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

final class QuoteRevisionMismatch extends \RuntimeException
{
    public static function forId(string $quoteId): self
    {
        return new self(sprintf('Quote "%s" changed since it was read; write refused.', $quoteId));
    }
}
```

- [ ] **Step 8: Run the full gate**

```bash
vendor/bin/phpunit
composer run format && composer run lint && composer run typecheck
composer run quality:filesize && composer run quality:dupes
```
Expected: all pass. If `quality:dupes` rises above 6%, the read-model objects are the likely cause — they are near-identical shells. Report rather than restructuring; the threshold was set at 6% for exactly this shape of code.

- [ ] **Step 9: Commit**

```bash
vendor/bin/mago fmt tests/
git add src/Bridge tests/Unit/Bridge
git commit -m "feat: Bridge\\Data value objects for the quote gateway"
```

---

### Task 3: The interface, the commercial adapters, and the factory gate

The seam. This is where untyped SwagCommercial objects get confined, and it is unit-testable with fakes — no shop needed.

**Files:**
- Create: `src/Bridge/QuoteGatewayInterface.php`
- Create: `src/Bridge/Commercial/QuoteProductAdderInterface.php`, `QuoteCommentWriterInterface.php`
- Create: `src/Bridge/Commercial/SwagCommercialProductAdder.php`, `SwagCommercialCommentWriter.php`
- Create: `src/Bridge/Commercial/CommercialAvailability.php`
- Test: `tests/Unit/Bridge/Commercial/CommercialAvailabilityTest.php`

**Interfaces:**
- Consumes: `Bridge\Data\*` from Task 2.
- Produces:
  - `QuoteGatewayInterface` with the seven methods below (exact signatures).
  - `QuoteProductAdderInterface::addProduct(string $quoteId, string $productId, int $quantity, Context $context): void`
  - `QuoteCommentWriterInterface::comment(string $quoteId, string $comment, Context $context): void`
  - `CommercialAvailability::isAvailableByClass(): bool` and `::isLicensed(): bool`

- [ ] **Step 1: Write the gateway interface**

`src/Bridge/QuoteGatewayInterface.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineItemChange;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteVersion;

/**
 * Everything the plugin is allowed to know about SwagCommercial's quotes.
 * No Shopware Context appears here on purpose: building and threading it is
 * the gateway's business, not its callers'.
 */
interface QuoteGatewayInterface
{
    /** @throws QuoteNotFoundException */
    public function fetchSnapshot(string $quoteId, QuoteVersion $version = QuoteVersion::Live): QuoteSnapshot;

    /**
     * @param list<QuoteLineItemChange> $changes
     * @throws QuoteNotFoundException
     * @throws QuoteRevisionMismatch
     */
    public function updateLineItems(string $quoteId, array $changes, ?QuoteRevision $expected = null): void;

    /** @throws QuoteNotFoundException */
    public function addProduct(string $quoteId, string $productId, int $quantity): void;

    /** @throws QuoteNotFoundException */
    public function recalculate(string $quoteId): void;

    /**
     * @throws QuoteNotFoundException
     * @throws QuoteRevisionMismatch
     */
    public function updateQuote(string $quoteId, QuoteUpdate $update, ?QuoteRevision $expected = null): void;

    /** @throws QuoteNotFoundException */
    public function addComment(string $quoteId, string $comment): void;

    /** @throws QuoteNotFoundException */
    public function transition(string $quoteId, QuoteTransition $action): void;
}
```

- [ ] **Step 2: Write the two narrow adapter interfaces**

These mirror only the commercial methods we call, in Shopware-core types (which typecheck fine).

`src/Bridge/Commercial/QuoteProductAdderInterface.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Commercial;

use Shopware\Core\Framework\Context;

/** Mirrors QuoteManipulation::addProduct(), which is @internal in SwagCommercial. */
interface QuoteProductAdderInterface
{
    public function addProduct(string $quoteId, string $productId, int $quantity, Context $context): void;
}
```

`src/Bridge/Commercial/QuoteCommentWriterInterface.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Commercial;

use Shopware\Core\Framework\Context;

/** Mirrors QuoteCommenter::comment(), which is @internal in SwagCommercial. */
interface QuoteCommentWriterInterface
{
    public function comment(string $quoteId, string $comment, Context $context): void;
}
```

- [ ] **Step 3: Write the adapters**

Non-nullable `object` (the null check lives in the factory), one `@mago-expect` per call site. `#[Override]` is mandatory.

`src/Bridge/Commercial/SwagCommercialProductAdder.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Commercial;

use Shopware\Core\Framework\Context;

/**
 * @internal-dependency
 * Shopware\Commercial\B2B\QuoteManagement\Domain\Admin\QuoteManipulation::addProduct()
 * — @internal in SwagCommercial. A release can change it without notice;
 * tests/Integration/ is what catches that, not static analysis.
 */
final readonly class SwagCommercialProductAdder implements QuoteProductAdderInterface
{
    public function __construct(private object $quoteManipulation) {}

    #[\Override]
    public function addProduct(string $quoteId, string $productId, int $quantity, Context $context): void
    {
        /** @mago-expect analysis:ambiguous-object-method-access */
        $this->quoteManipulation->addProduct($quoteId, $productId, $quantity, $context);
    }
}
```

`src/Bridge/Commercial/SwagCommercialCommentWriter.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Commercial;

use Shopware\Core\Framework\Context;

/**
 * @internal-dependency
 * Shopware\Commercial\B2B\QuoteManagement\Domain\Comment\QuoteCommenter::comment()
 * — @internal in SwagCommercial, and its $state parameter is
 * @deprecated tag:v6.8.0, so this call site needs revisiting for 6.8.
 */
final readonly class SwagCommercialCommentWriter implements QuoteCommentWriterInterface
{
    public function __construct(private object $quoteCommenter) {}

    #[\Override]
    public function comment(string $quoteId, string $comment, Context $context): void
    {
        // Signature: comment(Context, string $comment, string $quoteId, ...nullable).
        /** @mago-expect analysis:ambiguous-object-method-access */
        $this->quoteCommenter->comment($context, $comment, $quoteId);
    }
}
```

- [ ] **Step 4: Write the failing availability test**

`tests/Unit/Bridge/Commercial/CommercialAvailabilityTest.php`. On a dev machine SwagCommercial is absent, so this asserts the *degraded* path — which is the branch that must never throw.

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge\Commercial;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability;
use PHPUnit\Framework\TestCase;

final class CommercialAvailabilityTest extends TestCase
{
    public function testReportsUnavailableWhenSwagCommercialIsAbsent(): void
    {
        // This suite runs without SwagCommercial on purpose: the plugin must
        // install and run on a shop that does not have it.
        self::assertFalse(CommercialAvailability::isAvailableByClass());
    }

    public function testIsLicensedNeverThrowsWhenSwagCommercialIsAbsent(): void
    {
        self::assertFalse(CommercialAvailability::isLicensed());
    }
}
```

- [ ] **Step 5: Run to verify failure**

Run: `vendor/bin/phpunit --filter CommercialAvailabilityTest`
Expected: FAIL — class not found.

- [ ] **Step 6: Implement the availability gate**

Mirrors `agentic-commerce`'s `QuoteBackendFeature` (verified), but on the **service-level** toggle. `License::get()` returns `string|bool|int` and `false` means unavailable.

`src/Bridge/Commercial/CommercialAvailability.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Commercial;

/**
 * The two-stage gate from ADR 0001: class existence decides whether the
 * gateway is built, the license toggle decides whether it can serve.
 */
final class CommercialAvailability
{
    /**
     * The toggle every commercial quote SERVICE checks on entry. Not
     * QUOTE_MANAGEMENT-8702512, which only guards the Admin API route this
     * bridge deliberately does not use.
     */
    public const LICENSE_TOGGLE = 'QUOTE_MANAGEMENT-6302947';

    /** Class-name literals, not `::class`: these need not be loadable. */
    private const MANIPULATION_CLASS = 'Shopware\\Commercial\\B2B\\QuoteManagement\\Domain\\Admin\\QuoteManipulation';
    private const COMMENTER_CLASS = 'Shopware\\Commercial\\B2B\\QuoteManagement\\Domain\\Comment\\QuoteCommenter';
    private const LICENSE_CLASS = 'Shopware\\Commercial\\Licensing\\License';

    public static function isAvailableByClass(): bool
    {
        return class_exists(self::MANIPULATION_CLASS)
            && class_exists(self::COMMENTER_CLASS)
            && class_exists(self::LICENSE_CLASS);
    }

    public static function isLicensed(): bool
    {
        if (!self::isAvailableByClass()) {
            return false;
        }

        try {
            /** @var callable(string): (string|bool|int) $get */
            $get = [self::LICENSE_CLASS, 'get'];

            return $get(self::LICENSE_TOGGLE) !== false;
        } catch (\Throwable) {
            return false;
        }
    }
}
```

- [ ] **Step 7: Run to verify pass, then the gate**

```bash
vendor/bin/phpunit --filter CommercialAvailabilityTest
composer run format && composer run lint && composer run typecheck
```
Expected: tests PASS; `typecheck` clean. If `ambiguous-object-method-access` still surfaces, the `@mago-expect` is on the wrong line — it must sit immediately above the call, not above the method.

- [ ] **Step 8: Commit**

```bash
vendor/bin/mago fmt tests/
git add src/Bridge tests/Unit/Bridge
git commit -m "feat: quote gateway interface, commercial adapters, availability gate"
```

---

### Task 4: `fetchSnapshot`

First real gateway method, and the one every integration test after it uses to assert results.

**Files:**
- Create: `src/Bridge/SwagCommercialQuoteGateway.php`
- Create: `src/Bridge/QuoteSnapshotReader.php`
- Test: `tests/Integration/QuoteFixture.php`, `tests/Integration/FetchSnapshotTest.php`

**Interfaces:**
- Consumes: `QuoteGatewayInterface`, `Bridge\Data\*`, `CommercialAvailability`.
- Produces:
  - `SwagCommercialQuoteGateway` (constructor params established here; later tasks add to it — keep it at or under 5 by grouping collaborators if needed).
  - `QuoteSnapshotReader::read(string $quoteId, QuoteVersion $version, Context $context): QuoteSnapshot`
  - `QuoteFixture::createOpenQuote(ContainerInterface $c): string` returning a quote id, for reuse by Tasks 5–9.

- [ ] **Step 1: Write the quote fixture helper**

Creating a real B2B quote needs a customer and a product. Rather than hand-rolling that, drive SwagCommercial's own creation path.

`tests/Integration/QuoteFixture.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Finds an existing quote in the shop to exercise, rather than constructing
 * one: SwagCommercial's creation path needs a customer, a sales channel
 * context and a cart, and reproducing that here would test the fixture.
 */
final class QuoteFixture
{
    /** @throws \RuntimeException when the shop has no quote to work with */
    public static function anyQuoteId(ContainerInterface $container, Context $context): string
    {
        /** @var EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $repository */
        $repository = $container->get('quote.repository');

        $id = $repository->searchIds(new Criteria(), $context)->firstId();

        if ($id === null) {
            throw new \RuntimeException(
                'No quote exists in the shop. Create one through the storefront or admin first — '
                . 'see the plan Task 4 Step 1 for why this is not generated.'
            );
        }

        return $id;
    }
}
```

- [ ] **Step 2: Write the failing integration test**

`tests/Integration/FetchSnapshotTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteVersion;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Bridge\QuoteNotFoundException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;

final class FetchSnapshotTest extends IntegrationTestCase
{
    public function testReadsARealQuote(): void
    {
        $context = Context::createDefaultContext();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), $context);

        $snapshot = $this->gateway()->fetchSnapshot($quoteId);

        self::assertSame($quoteId, $snapshot->identity->quoteId);
        self::assertNotSame('', $snapshot->identity->quoteNumber);
        self::assertSame(3, \strlen($snapshot->identity->currencyIso), 'currencyIso should be an ISO 4217 code');
        self::assertNotSame('', $snapshot->lifecycle->stateTechnicalName);
        self::assertNotSame('', $snapshot->revision->versionId);
    }

    public function testUnknownQuoteThrows(): void
    {
        $this->expectException(QuoteNotFoundException::class);

        $this->gateway()->fetchSnapshot(Uuid::randomHex());
    }

    public function testSnapshotLaneIsReadableAndDistinctFromLive(): void
    {
        $context = Context::createDefaultContext();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), $context);

        $live = $this->gateway()->fetchSnapshot($quoteId, QuoteVersion::Live);

        // The snapshot lane may legitimately be absent for a given state; when
        // it resolves, it must be a different version id than live.
        try {
            $snapshot = $this->gateway()->fetchSnapshot($quoteId, QuoteVersion::Snapshot);
            self::assertNotSame($live->revision->versionId, $snapshot->revision->versionId);
        } catch (QuoteNotFoundException) {
            self::markTestSkipped('This quote has no snapshot version in its current state.');
        }
    }

    private function gateway(): QuoteGatewayInterface
    {
        $gateway = static::getContainer()->get(QuoteGatewayInterface::class);
        self::assertInstanceOf(QuoteGatewayInterface::class, $gateway);

        return $gateway;
    }
}
```

- [ ] **Step 3: Run to verify failure**

Run: `composer run test:integration`
Expected: FAIL — `QuoteGatewayInterface` not registered in the container (Task 9 wires it; until then, if `get()` returns null the test fails at the `assertInstanceOf`). To unblock, temporarily construct the gateway by hand in `gateway()` from container services; replace with the DI lookup in Task 9.

- [ ] **Step 4: Implement the snapshot reader**

`src/Bridge/QuoteSnapshotReader.php`. The snapshot version id mirrors `QuoteEntity::SNAPSHOT_VERSION_ID` and must be a literal, since we cannot reference the class.

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteVersion;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;

/** Maps QuoteVersion onto the DAL version ids SwagCommercial uses. */
final class QuoteVersionResolver
{
    /**
     * Mirrors Shopware\Commercial\B2B\QuoteManagement\Entity\Quote\QuoteEntity::SNAPSHOT_VERSION_ID.
     * A literal because that class need not be loadable — if SwagCommercial
     * ever changes it, tests/Integration/ is what catches it.
     */
    public const SNAPSHOT_VERSION_ID = '019cfaaf020219939ba2eea26ba651ae';

    public function contextFor(Context $context, QuoteVersion $version): Context
    {
        return match ($version) {
            QuoteVersion::Live => $context->createWithVersionId(Defaults::LIVE_VERSION),
            QuoteVersion::Snapshot => $context->createWithVersionId(self::SNAPSHOT_VERSION_ID),
        };
    }
}
```

Put that in its own file `src/Bridge/QuoteVersionResolver.php` (one class per file), then write the reader:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteComment;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteContent;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTotals;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteVersion;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

/**
 * Reads a quote through the generic DAL and maps it to the bridge's own read
 * model. No SwagCommercial class is named: `quote.repository` is resolved by
 * string id, and fields are read via Entity::get().
 */
final readonly class QuoteSnapshotReader
{
    /** @param EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $quoteRepository */
    public function __construct(
        private EntityRepository $quoteRepository,
        private QuoteVersionResolver $versionResolver,
    ) {}

    /** @throws QuoteNotFoundException */
    public function read(string $quoteId, QuoteVersion $version, Context $context): QuoteSnapshot
    {
        $versionedContext = $this->versionResolver->contextFor($context, $version);

        $criteria = new Criteria([$quoteId]);
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('comments');
        $criteria->addAssociation('stateMachineState');
        $criteria->addAssociation('currency');

        $quote = $this->quoteRepository->search($criteria, $versionedContext)->getEntities()->first();

        if (!$quote instanceof Entity) {
            throw QuoteNotFoundException::forId($quoteId);
        }

        return new QuoteSnapshot(
            identity: $this->readIdentity($quote, $quoteId),
            revision: $this->readRevision($quote, $versionedContext),
            totals: new QuoteTotals(totalNet: (float) $quote->get('amountNet')),
            lifecycle: $this->readLifecycle($quote),
            content: new QuoteContent(lines: $this->readLines($quote), comments: $this->readComments($quote)),
        );
    }
}
```

- [ ] **Step 5: Implement the reader's private mappers**

Append to `QuoteSnapshotReader`. Keep the class under 10 cyclomatic complexity — if `typecheck`/`lint` complains, split the line and comment mapping into their own classes (`QuoteLineMapper`, `QuoteCommentMapper`), which is the same move the Policy module made.

```php
    private function readIdentity(Entity $quote, string $quoteId): QuoteIdentity
    {
        $currency = $quote->get('currency');
        $iso = $currency instanceof Entity ? (string) $currency->get('isoCode') : '';

        return new QuoteIdentity(
            quoteId: $quoteId,
            quoteNumber: (string) $quote->get('quoteNumber'),
            currencyIso: $iso,
        );
    }

    private function readRevision(Entity $quote, Context $context): QuoteRevision
    {
        $updatedAt = $quote->get('updatedAt') ?? $quote->get('createdAt');

        return new QuoteRevision(
            versionId: $context->getVersionId(),
            updatedAt: $updatedAt instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($updatedAt)
                : new \DateTimeImmutable('@0'),
        );
    }

    private function readLifecycle(Entity $quote): QuoteLifecycle
    {
        $state = $quote->get('stateMachineState');
        $expiresAt = $quote->get('expirationDate');
        $customFields = $quote->get('customFields');

        return new QuoteLifecycle(
            stateTechnicalName: $state instanceof Entity ? (string) $state->get('technicalName') : '',
            expiresAt: $expiresAt instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($expiresAt)
                : null,
            customFields: \is_array($customFields) ? $customFields : [],
        );
    }

    /** @return list<QuoteLineSnapshot> */
    private function readLines(Entity $quote): array
    {
        $lineItems = $quote->get('lineItems');
        $lines = [];

        if (!is_iterable($lineItems)) {
            return $lines;
        }

        foreach ($lineItems as $lineItem) {
            if (!$lineItem instanceof Entity || $lineItem->get('deletedAt') !== null) {
                continue;
            }

            $lines[] = new QuoteLineSnapshot(
                identity: new QuoteLineIdentity(
                    lineItemId: (string) $lineItem->get('id'),
                    label: $this->nullableString($lineItem->get('label')),
                    productId: $this->nullableString($lineItem->get('referencedId')),
                ),
                quantity: (int) $lineItem->get('quantity'),
                unitPriceNet: (float) $lineItem->get('unitPrice'),
                totalNet: (float) $lineItem->get('totalPrice'),
                requestedUnitPrice: $lineItem->get('requestedPrice') === null
                    ? null
                    : (float) $lineItem->get('requestedPrice'),
            );
        }

        return $lines;
    }

    /** @return list<QuoteComment> */
    private function readComments(Entity $quote): array
    {
        $comments = $quote->get('comments');
        $result = [];

        if (!is_iterable($comments)) {
            return $result;
        }

        foreach ($comments as $comment) {
            if (!$comment instanceof Entity) {
                continue;
            }

            $createdAt = $comment->get('createdAt');
            $result[] = new QuoteComment(
                comment: (string) $comment->get('comment'),
                lineItemId: $this->nullableString($comment->get('quoteLineItemId')),
                createdById: $this->nullableString($comment->get('createdById')),
                customerId: $this->nullableString($comment->get('customerId')),
                createdAt: $createdAt instanceof \DateTimeInterface
                    ? \DateTimeImmutable::createFromInterface($createdAt)
                    : null,
            );
        }

        return $result;
    }

    private function nullableString(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }
```

- [ ] **Step 6: Implement the gateway with `fetchSnapshot` only**

`src/Bridge/SwagCommercialQuoteGateway.php`. The remaining six methods get added in Tasks 5–9; for now they throw so the class is complete and analyzable.

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteVersion;
use Shopware\Core\Framework\Context;

/**
 * The only class in this plugin that reaches SwagCommercial. Its @internal
 * dependencies are confined to Bridge\Commercial adapters and listed there.
 */
final readonly class SwagCommercialQuoteGateway implements QuoteGatewayInterface
{
    public function __construct(private QuoteSnapshotReader $reader) {}

    #[\Override]
    public function fetchSnapshot(string $quoteId, QuoteVersion $version = QuoteVersion::Live): QuoteSnapshot
    {
        return $this->reader->read($quoteId, $version, Context::createDefaultContext());
    }

    #[\Override]
    public function updateLineItems(string $quoteId, array $changes, ?QuoteRevision $expected = null): void
    {
        throw new \LogicException('Not implemented until plan Task 5.');
    }

    #[\Override]
    public function addProduct(string $quoteId, string $productId, int $quantity): void
    {
        throw new \LogicException('Not implemented until plan Task 7.');
    }

    #[\Override]
    public function recalculate(string $quoteId): void
    {
        throw new \LogicException('Not implemented until plan Task 7.');
    }

    #[\Override]
    public function updateQuote(string $quoteId, QuoteUpdate $update, ?QuoteRevision $expected = null): void
    {
        throw new \LogicException('Not implemented until plan Task 6.');
    }

    #[\Override]
    public function addComment(string $quoteId, string $comment): void
    {
        throw new \LogicException('Not implemented until plan Task 8.');
    }

    #[\Override]
    public function transition(string $quoteId, QuoteTransition $action): void
    {
        throw new \LogicException('Not implemented until plan Task 8.');
    }
}
```

- [ ] **Step 7: Run to verify pass**

Run: `composer run test:integration`
Expected: `FetchSnapshotTest` passes (or skips the snapshot-lane case). If field names are wrong (`amountNet`, `quoteNumber`, `unitPrice`, `requestedPrice`), read `/Users/sebastian/projects/SwagCommercial/src/B2B/QuoteManagement/Entity/Quote/QuoteDefinition.php` and correct them — **that is a finding, note it in the test docblock.**

- [ ] **Step 8: Full gate and commit**

```bash
vendor/bin/phpunit && composer run format && composer run lint && composer run typecheck && composer run quality:filesize
vendor/bin/mago fmt tests/
git add src/Bridge tests/Integration
git commit -m "feat: fetchSnapshot over the generic DAL, with the bridge read model"
```

---

### Task 5: `updateLineItems` and the custom-price constraint

The most important task in the plan. The failure mode being defended against is a price write that appears to succeed and is silently reverted by the next recalculation.

**Files:**
- Create: `src/Bridge/QuoteLineItemWriter.php`
- Modify: `src/Bridge/SwagCommercialQuoteGateway.php`
- Test: `tests/Integration/UpdateLineItemsTest.php`

**Interfaces:**
- Consumes: `QuoteLineItemChange`, `QuoteSnapshotReader`.
- Produces: `QuoteLineItemWriter::write(string $quoteId, list<QuoteLineItemChange> $changes, Context $context): void`.

- [ ] **Step 1: Write the failing test — price survival across recalculate**

`tests/Integration/UpdateLineItemsTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineItemChange;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use Shopware\Core\Framework\Context;

final class UpdateLineItemsTest extends IntegrationTestCase
{
    /**
     * THE load-bearing test. A repriced line is only honoured if it also
     * carries customFields['quote_custom_offer_price'] === true; without it,
     * recalculate() re-prices from the catalog and silently discards the
     * priceDefinition we wrote.
     */
    public function testRepricedLineSurvivesRecalculate(): void
    {
        $gateway = $this->gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());

        $before = $gateway->fetchSnapshot($quoteId);
        $line = $before->content->lines[0] ?? null;
        self::assertNotNull($line, 'The fixture quote needs at least one line item.');

        $target = round($line->unitPriceNet * 0.9, 2);
        $gateway->updateLineItems($quoteId, [
            new QuoteLineItemChange(lineItemId: $line->identity->lineItemId, unitPriceNet: $target),
        ]);

        $gateway->recalculate($quoteId);

        $after = $gateway->fetchSnapshot($quoteId);
        $sameLine = null;
        foreach ($after->content->lines as $candidate) {
            if ($candidate->identity->lineItemId === $line->identity->lineItemId) {
                $sameLine = $candidate;
            }
        }

        self::assertNotNull($sameLine, 'The repriced line disappeared after recalculate.');
        self::assertEqualsWithDelta(
            $target,
            $sameLine->unitPriceNet,
            0.01,
            'Price did not survive recalculate — the custom-price flag is missing or ineffective.'
        );
    }

    public function testQuantityChangeApplies(): void
    {
        $gateway = $this->gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());

        $line = $gateway->fetchSnapshot($quoteId)->content->lines[0] ?? null;
        self::assertNotNull($line);

        $gateway->updateLineItems($quoteId, [
            new QuoteLineItemChange(lineItemId: $line->identity->lineItemId, quantity: $line->quantity + 1),
        ]);

        $after = $gateway->fetchSnapshot($quoteId);
        foreach ($after->content->lines as $candidate) {
            if ($candidate->identity->lineItemId === $line->identity->lineItemId) {
                self::assertSame($line->quantity + 1, $candidate->quantity);

                return;
            }
        }

        self::fail('Line item vanished after a quantity change.');
    }

    public function testRemovalSoftDeletesTheLine(): void
    {
        $gateway = $this->gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());

        $lines = $gateway->fetchSnapshot($quoteId)->content->lines;
        self::assertGreaterThanOrEqual(1, \count($lines));
        $victim = $lines[0]->identity->lineItemId;

        $gateway->updateLineItems($quoteId, [new QuoteLineItemChange(lineItemId: $victim, remove: true)]);

        foreach ($gateway->fetchSnapshot($quoteId)->content->lines as $line) {
            self::assertNotSame($victim, $line->identity->lineItemId, 'Removed line still reads back.');
        }
    }

    private function gateway(): QuoteGatewayInterface
    {
        $gateway = static::getContainer()->get(QuoteGatewayInterface::class);
        self::assertInstanceOf(QuoteGatewayInterface::class, $gateway);

        return $gateway;
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `composer run test:integration`
Expected: FAIL — `LogicException: Not implemented until plan Task 5.`

- [ ] **Step 3: Implement the line item writer**

`src/Bridge/QuoteLineItemWriter.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineItemChange;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

/**
 * Writes line-item changes through the generic DAL in one batched update.
 *
 * A repriced line MUST also carry customFields['quote_custom_offer_price'] =>
 * true. SwagCommercial's QuoteLineItemTransformer only adds the
 * ProductCartProcessor::CUSTOM_PRICE extension when that flag is present, and
 * without the extension the next recalculate() re-prices the line from the
 * catalog and silently discards the priceDefinition below.
 */
final readonly class QuoteLineItemWriter
{
    private const CUSTOM_PRICE_FLAG = 'quote_custom_offer_price';

    /** ponytail: 19% assumed, as the TS implementation did. Affects displayed
     * VAT only, not the net price the policy layer verifies. Echo the line's
     * real rate here if non-19% products matter. */
    private const ASSUMED_TAX_RATE = 19.0;

    /** @param EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $lineItemRepository */
    public function __construct(private EntityRepository $lineItemRepository) {}

    /** @param list<QuoteLineItemChange> $changes */
    public function write(array $changes, Context $context): void
    {
        $payload = [];

        foreach ($changes as $change) {
            $row = $this->rowFor($change);
            if ($row !== []) {
                $payload[] = ['id' => $change->lineItemId, ...$row];
            }
        }

        if ($payload !== []) {
            $this->lineItemRepository->update($payload, $context);
        }
    }

    /** @return array<string, mixed> */
    private function rowFor(QuoteLineItemChange $change): array
    {
        if ($change->isRemoval()) {
            // Soft delete, matching SwagCommercial's own model: the
            // quote-to-cart transformer and calculator skip deletedAt lines.
            return ['deletedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM)];
        }

        $row = [];

        if ($change->quantity !== null) {
            $row['quantity'] = $change->quantity;
        }

        if ($change->touchesPrice()) {
            $row += $this->priceRow($change);
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function priceRow(QuoteLineItemChange $change): array
    {
        return [
            'priceDefinition' => [
                'type' => 'quantity',
                'price' => $change->unitPriceNet,
                'quantity' => $change->quantity ?? 1,
                'isCalculated' => true,
                'taxRules' => [['taxRate' => self::ASSUMED_TAX_RATE, 'percentage' => 100]],
            ],
            'customFields' => [self::CUSTOM_PRICE_FLAG => true],
        ];
    }
}
```

- [ ] **Step 4: Wire it into the gateway**

In `SwagCommercialQuoteGateway`, add `private QuoteLineItemWriter $lineItemWriter` to the constructor and replace the stub:

```php
    #[\Override]
    public function updateLineItems(string $quoteId, array $changes, ?QuoteRevision $expected = null): void
    {
        $context = Context::createDefaultContext();
        $this->assertRevision($quoteId, $expected, $context);
        $this->lineItemWriter->write($changes, $context);
    }
```

And add the precondition helper (fully implemented in Task 9; a no-op stub now keeps the signature honest):

```php
    /** @throws QuoteNotFoundException|QuoteRevisionMismatch */
    private function assertRevision(string $quoteId, ?QuoteRevision $expected, Context $context): void
    {
        if ($expected === null) {
            return;
        }

        $current = $this->reader->read($quoteId, QuoteVersion::Live, $context)->revision;

        if (!$current->matches($expected)) {
            throw QuoteRevisionMismatch::forId($quoteId);
        }
    }
```

- [ ] **Step 5: Run to verify pass**

Run: `composer run test:integration`
Expected: the quantity and removal tests pass. `testRepricedLineSurvivesRecalculate` may still fail — **it depends on Task 7's `recalculate`**. If so, mark it skipped with a `// unskip in Task 7` comment and revisit; do not weaken the assertion.

- [ ] **Step 6: Record the tax-mode finding**

Read back what the price actually became. If `unitPriceNet` comes back divided or multiplied by ~1.19, the cart's tax mode differs from the stored price space and the TS implementation's measure-and-rewrite second pass is needed. Write the observed behaviour into the test's class docblock and add a line to the spec's Testing section. **This is a spike deliverable, not optional.**

- [ ] **Step 7: Full gate and commit**

```bash
vendor/bin/phpunit && composer run format && composer run lint && composer run typecheck
vendor/bin/mago fmt tests/
git add src/Bridge tests/Integration
git commit -m "feat: updateLineItems with the custom-price flag recalculate requires"
```

---

### Task 6: `updateQuote` and `customFields` shallow-merge

**Files:**
- Create: `src/Bridge/QuoteWriter.php`
- Modify: `src/Bridge/SwagCommercialQuoteGateway.php`
- Test: `tests/Integration/UpdateQuoteTest.php`

**Interfaces:**
- Consumes: `QuoteUpdate`, `Discount`, `DiscountType`.
- Produces: `QuoteWriter::write(string $quoteId, QuoteUpdate $update, Context $context): void`.

- [ ] **Step 1: Write the failing test**

`tests/Integration/UpdateQuoteTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Data\Discount;
use MerchantQuoteAgentPlugin\Bridge\Data\DiscountType;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use Shopware\Core\Framework\Context;

final class UpdateQuoteTest extends IntegrationTestCase
{
    public function testExpirationAndDiscountApply(): void
    {
        $gateway = $this->gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $expires = new \DateTimeImmutable('+14 days');

        $gateway->updateQuote($quoteId, new QuoteUpdate(
            discount: new Discount(DiscountType::Percentage, 5.0),
            expiresAt: $expires,
        ));

        $after = $gateway->fetchSnapshot($quoteId);

        self::assertNotNull($after->lifecycle->expiresAt);
        self::assertSame($expires->format('Y-m-d'), $after->lifecycle->expiresAt->format('Y-m-d'));
    }

    /**
     * The A2CN act chain stores one act per top-level customFields key so that
     * buyer and seller appends never collide. A replacing write would destroy
     * the counterparty's acts, so merge behaviour is a correctness requirement.
     */
    public function testCustomFieldsMergeRatherThanReplace(): void
    {
        $gateway = $this->gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());

        $gateway->updateQuote($quoteId, new QuoteUpdate(customFields: ['a2cn_act_0001_b' => 'buyer']));
        $gateway->updateQuote($quoteId, new QuoteUpdate(customFields: ['a2cn_act_0001_s' => 'seller']));

        $fields = $gateway->fetchSnapshot($quoteId)->lifecycle->customFields;

        self::assertSame('buyer', $fields['a2cn_act_0001_b'] ?? null, 'Second write clobbered the first key.');
        self::assertSame('seller', $fields['a2cn_act_0001_s'] ?? null);
    }

    public function testEmptyUpdateIsANoOp(): void
    {
        $gateway = $this->gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());

        $before = $gateway->fetchSnapshot($quoteId);
        $gateway->updateQuote($quoteId, new QuoteUpdate());
        $after = $gateway->fetchSnapshot($quoteId);

        self::assertSame($before->totals->totalNet, $after->totals->totalNet);
    }

    private function gateway(): QuoteGatewayInterface
    {
        $gateway = static::getContainer()->get(QuoteGatewayInterface::class);
        self::assertInstanceOf(QuoteGatewayInterface::class, $gateway);

        return $gateway;
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `composer run test:integration`
Expected: FAIL — `LogicException: Not implemented until plan Task 6.`

- [ ] **Step 3: Implement the quote writer**

`src/Bridge/QuoteWriter.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

/**
 * Quote-level field writes, batched into one generic-DAL update.
 *
 * customFields are passed as the given top-level keys only. Shopware's
 * CustomFields field type merges rather than replaces, which is what keeps the
 * A2CN act chain intact; UpdateQuoteTest verifies that rather than assuming it.
 */
final readonly class QuoteWriter
{
    /** @param EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $quoteRepository */
    public function __construct(private EntityRepository $quoteRepository) {}

    public function write(string $quoteId, QuoteUpdate $update, Context $context): void
    {
        if ($update->isEmpty()) {
            return;
        }

        $payload = ['id' => $quoteId];

        if ($update->discount !== null) {
            $payload['discount'] = [
                'type' => $update->discount->type->value,
                'value' => $update->discount->value,
            ];
        }

        if ($update->expiresAt !== null) {
            $payload['expirationDate'] = $update->expiresAt->format(\DATE_ATOM);
        }

        if ($update->customFields !== null) {
            $payload['customFields'] = $update->customFields;
        }

        $this->quoteRepository->update([$payload], $context);
    }
}
```

- [ ] **Step 4: Wire into the gateway**

Add `private QuoteWriter $quoteWriter` to the constructor and replace the stub:

```php
    #[\Override]
    public function updateQuote(string $quoteId, QuoteUpdate $update, ?QuoteRevision $expected = null): void
    {
        $context = Context::createDefaultContext();
        $this->assertRevision($quoteId, $expected, $context);
        $this->quoteWriter->write($quoteId, $update, $context);
    }
```

**Constructor param count check:** the gateway now holds reader, lineItemWriter, quoteWriter and will gain more in Tasks 7–8. Before exceeding 5, group them — introduce `src/Bridge/QuoteWriters.php`, a readonly holder taking `QuoteLineItemWriter`, `QuoteWriter`, and later the product/comment/transition collaborators, and pass that single object to the gateway.

- [ ] **Step 5: Run to verify pass**

Run: `composer run test:integration`
Expected: all three `UpdateQuoteTest` cases pass. **If `testCustomFieldsMergeRatherThanReplace` fails, stop.** That means the DAL replaces rather than merges, and the fix is a read-modify-write inside `QuoteWriter` (read current customFields, array-merge, write the union) — implement that, then re-run. Record which behaviour was observed in the class docblock either way.

- [ ] **Step 6: Full gate and commit**

```bash
vendor/bin/phpunit && composer run format && composer run lint && composer run typecheck
vendor/bin/mago fmt tests/
git add src/Bridge tests/Integration
git commit -m "feat: updateQuote with customFields merge the act chain depends on"
```

---

### Task 7: `addProduct` and `recalculate`

The first two calls that go through SwagCommercial services rather than the generic DAL.

**Files:**
- Create: `src/Bridge/QuoteRecalculator.php`
- Modify: `src/Bridge/SwagCommercialQuoteGateway.php`, `src/Bridge/QuoteWriters.php`
- Test: `tests/Integration/AddProductAndRecalculateTest.php`

**Interfaces:**
- Consumes: `QuoteProductAdderInterface` (Task 3).
- Produces: `QuoteRecalculator::recalculate(string $quoteId, Context $context): void`.

- [ ] **Step 1: Write the failing test**

`tests/Integration/AddProductAndRecalculateTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

final class AddProductAndRecalculateTest extends IntegrationTestCase
{
    public function testAddingAProductGrowsTheQuote(): void
    {
        $gateway = $this->gateway();
        $context = Context::createDefaultContext();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), $context);

        $before = \count($gateway->fetchSnapshot($quoteId)->content->lines);
        $gateway->addProduct($quoteId, $this->anyProductId($context), 2);
        $after = \count($gateway->fetchSnapshot($quoteId)->content->lines);

        self::assertGreaterThan($before, $after, 'addProduct did not add a line.');
    }

    public function testRecalculateLeavesTheQuoteReadable(): void
    {
        $gateway = $this->gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());

        $gateway->recalculate($quoteId);

        self::assertGreaterThanOrEqual(0.0, $gateway->fetchSnapshot($quoteId)->totals->totalNet);
    }

    private function anyProductId(Context $context): string
    {
        /** @var EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $products */
        $products = static::getContainer()->get('product.repository');
        $id = $products->searchIds((new Criteria())->setLimit(1), $context)->firstId();
        self::assertIsString($id, 'The shop has no product to add.');

        return $id;
    }

    private function gateway(): QuoteGatewayInterface
    {
        $gateway = static::getContainer()->get(QuoteGatewayInterface::class);
        self::assertInstanceOf(QuoteGatewayInterface::class, $gateway);

        return $gateway;
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `composer run test:integration`
Expected: FAIL — `LogicException: Not implemented until plan Task 7.`

- [ ] **Step 3: Implement the recalculator**

`QuoteCalculator::recalculate()` needs a `SalesChannelContext`, which only `SalesChannelContextRestorer::restoreByQuote()` can build for an existing quote. Neither class is `@internal`, but neither is loadable at analysis time either — so both are held as `object` behind `@mago-expect`.

`src/Bridge/QuoteRecalculator.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use Shopware\Core\Framework\Context;

/**
 * Recalculation needs two commercial services, neither @internal:
 * SalesChannelContextRestorer::restoreByQuote() to build the
 * SalesChannelContext, then QuoteCalculator::recalculate() to reprice.
 */
final readonly class QuoteRecalculator
{
    public function __construct(
        private object $contextRestorer,
        private object $quoteCalculator,
    ) {}

    public function recalculate(string $quoteId, Context $context): void
    {
        /** @mago-expect analysis:ambiguous-object-method-access */
        $salesChannelContext = $this->contextRestorer->restoreByQuote($quoteId, $context);

        /** @mago-expect analysis:ambiguous-object-method-access */
        $this->quoteCalculator->recalculate($quoteId, $salesChannelContext);
    }
}
```

- [ ] **Step 4: Wire both into the gateway**

Add `QuoteProductAdderInterface` and `QuoteRecalculator` to `QuoteWriters`, then:

```php
    #[\Override]
    public function addProduct(string $quoteId, string $productId, int $quantity): void
    {
        $this->writers->productAdder->addProduct($quoteId, $productId, $quantity, Context::createDefaultContext());
    }

    #[\Override]
    public function recalculate(string $quoteId): void
    {
        $this->writers->recalculator->recalculate($quoteId, Context::createDefaultContext());
    }
```

- [ ] **Step 5: Run to verify pass, and unskip Task 5's price test**

Run: `composer run test:integration`
Expected: this task's tests pass, **and** `UpdateLineItemsTest::testRepricedLineSurvivesRecalculate` now runs for real. Remove the skip added in Task 5 Step 5. If the price does not survive, the custom-price flag write is wrong — compare against `QuoteLineItemTransformer.php:92` and fix `QuoteLineItemWriter::priceRow()`.

- [ ] **Step 6: Full gate and commit**

```bash
vendor/bin/phpunit && composer run format && composer run lint && composer run typecheck
vendor/bin/mago fmt tests/
git add src/Bridge tests/Integration
git commit -m "feat: addProduct and recalculate through the commercial services"
```

---

### Task 8: `addComment` and `transition`

Two small methods carrying two spike findings.

**Files:**
- Create: `src/Bridge/QuoteStateTransitioner.php`
- Modify: `src/Bridge/SwagCommercialQuoteGateway.php`, `src/Bridge/QuoteWriters.php`
- Test: `tests/Integration/AddCommentTest.php`, `tests/Integration/TransitionTest.php`

**Interfaces:**
- Consumes: `QuoteCommentWriterInterface` (Task 3), `QuoteTransition`.
- Produces: `QuoteStateTransitioner::transition(string $quoteId, QuoteTransition $action, Context $context): void`.

- [ ] **Step 1: Write the failing comment test, including the authorship finding**

`tests/Integration/AddCommentTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use Shopware\Core\Framework\Context;

final class AddCommentTest extends IntegrationTestCase
{
    public function testCommentIsReadableBack(): void
    {
        $gateway = $this->gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $text = 'Agent offer note ' . uniqid('', false);

        $gateway->addComment($quoteId, $text);

        $comments = $gateway->fetchSnapshot($quoteId)->content->comments;
        $texts = array_map(static fn ($c): string => $c->comment, $comments);

        self::assertContains($text, $texts);
    }

    /**
     * SPIKE FINDING (issue #4 depends on this): QuoteCommenter derives
     * createdById from AdminApiSource::getUserId(), which does not exist in a
     * message handler. If createdById, customerId and employeeId are all null,
     * an agent comment is indistinguishable from a buyer's by author alone and
     * the re-entrancy check needs another discriminator.
     */
    public function testRecordsWhatAuthorshipFieldsOurCommentActuallyGets(): void
    {
        $gateway = $this->gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $text = 'Authorship probe ' . uniqid('', false);

        $gateway->addComment($quoteId, $text);

        $ours = null;
        foreach ($gateway->fetchSnapshot($quoteId)->content->comments as $comment) {
            if ($comment->comment === $text) {
                $ours = $comment;
            }
        }

        self::assertNotNull($ours);

        // Not an assertion of desired behaviour — a record of observed
        // behaviour. Update this docblock with the result and copy it into the
        // spec's Testing section.
        self::assertTrue(
            true,
            sprintf(
                'observed: createdById=%s customerId=%s',
                var_export($ours->createdById, true),
                var_export($ours->customerId, true),
            )
        );
    }

    private function gateway(): QuoteGatewayInterface
    {
        $gateway = static::getContainer()->get(QuoteGatewayInterface::class);
        self::assertInstanceOf(QuoteGatewayInterface::class, $gateway);

        return $gateway;
    }
}
```

- [ ] **Step 2: Write the failing transition test, including the expiration-ordering finding**

`tests/Integration/TransitionTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use Shopware\Core\Framework\Context;

final class TransitionTest extends IntegrationTestCase
{
    public function testProcessTransitionMovesState(): void
    {
        $gateway = $this->gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());

        $before = $gateway->fetchSnapshot($quoteId)->lifecycle->stateTechnicalName;

        try {
            $gateway->transition($quoteId, QuoteTransition::Process);
        } catch (\Throwable $e) {
            self::markTestSkipped(sprintf('Quote in state "%s" cannot process: %s', $before, $e->getMessage()));
        }

        self::assertNotSame($before, $gateway->fetchSnapshot($quoteId)->lifecycle->stateTechnicalName);
    }

    /**
     * SPIKE FINDING: the TS implementation had to call setExpiration BEFORE the
     * `sent` transition or a background job auto-expired the quote. Establish
     * whether that still holds for in-process writes, and record the answer.
     */
    public function testExpirationSetBeforeSentSurvives(): void
    {
        $gateway = $this->gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $expires = new \DateTimeImmutable('+14 days');

        $gateway->updateQuote($quoteId, new QuoteUpdate(expiresAt: $expires));

        try {
            $gateway->transition($quoteId, QuoteTransition::Sent);
        } catch (\Throwable $e) {
            self::markTestSkipped('Quote cannot be sent from its current state: ' . $e->getMessage());
        }

        $after = $gateway->fetchSnapshot($quoteId);
        self::assertNotSame('expired', $after->lifecycle->stateTechnicalName, 'Quote auto-expired after sent.');
        self::assertNotNull($after->lifecycle->expiresAt);
    }

    private function gateway(): QuoteGatewayInterface
    {
        $gateway = static::getContainer()->get(QuoteGatewayInterface::class);
        self::assertInstanceOf(QuoteGatewayInterface::class, $gateway);

        return $gateway;
    }
}
```

- [ ] **Step 3: Run to verify failure**

Run: `composer run test:integration`
Expected: FAIL — `LogicException: Not implemented until plan Task 8.`

- [ ] **Step 4: Implement the transitioner using Shopware core only**

`src/Bridge/QuoteStateTransitioner.php`. No SwagCommercial class here at all — `QuoteState` was deliberately dropped, so `StateMachineRegistry` is called directly and the entity name is the literal `'quote'`.

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;

/**
 * SwagCommercial's QuoteState::transition() is @internal and is a
 * License::check plus exactly this call plus a null check, so we call Shopware
 * core directly and keep the @internal list at two. The license check the
 * wrapper would have done is covered by CommercialAvailability at the factory.
 */
final readonly class QuoteStateTransitioner
{
    /** Mirrors QuoteDefinition::ENTITY_NAME. */
    private const QUOTE_ENTITY = 'quote';

    private const STATE_FIELD = 'stateId';

    public function __construct(private StateMachineRegistry $stateMachineRegistry) {}

    public function transition(string $quoteId, QuoteTransition $action, Context $context): void
    {
        $this->stateMachineRegistry->transition(
            new Transition(self::QUOTE_ENTITY, $quoteId, $action->value, self::STATE_FIELD),
            $context,
        );
    }
}
```

- [ ] **Step 5: Wire both into the gateway**

Add `QuoteCommentWriterInterface` and `QuoteStateTransitioner` to `QuoteWriters`, then:

```php
    #[\Override]
    public function addComment(string $quoteId, string $comment): void
    {
        $this->writers->commentWriter->comment($quoteId, $comment, Context::createDefaultContext());
    }

    #[\Override]
    public function transition(string $quoteId, QuoteTransition $action): void
    {
        $this->writers->transitioner->transition($quoteId, $action, Context::createDefaultContext());
    }
```

- [ ] **Step 6: Run, and write both findings down**

Run: `composer run test:integration`

Then, for each of the two finding tests, replace its docblock's "establish whether" with the observed result, and add both to the spec's Testing section under a new "Spike results" heading. Findings to capture verbatim: the authorship fields our comment gets, and whether expiration-before-`sent` is still required.

- [ ] **Step 7: Full gate and commit**

```bash
vendor/bin/phpunit && composer run format && composer run lint && composer run typecheck
vendor/bin/mago fmt tests/
git add src/Bridge tests/Integration docs/superpowers/specs
git commit -m "feat: addComment and transition, with the authorship and expiry findings"
```

---

### Task 9: Revision precondition, DI wiring, and spec write-back

Makes the gateway reachable through DI, establishes what the write precondition actually guarantees, and closes the issue's done-when criteria.

**Files:**
- Create: `src/Bridge/QuoteGatewayFactory.php`
- Modify: `src/Resources/config/services.php`
- Modify: `docs/superpowers/specs/2026-08-26-shopware-quote-bridge-design.md`
- Test: `tests/Integration/RevisionPreconditionTest.php`, `tests/Integration/GatewayWiringTest.php`

**Interfaces:**
- Consumes: everything above.
- Produces: `QuoteGatewayFactory::create(): ?QuoteGatewayInterface` — null when SwagCommercial is absent or unlicensed.

- [ ] **Step 1: Write the failing precondition test**

`tests/Integration/RevisionPreconditionTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Bridge\QuoteRevisionMismatch;
use Shopware\Core\Framework\Context;

final class RevisionPreconditionTest extends IntegrationTestCase
{
    public function testWriteSucceedsWithAFreshRevision(): void
    {
        $gateway = $this->gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());

        $revision = $gateway->fetchSnapshot($quoteId)->revision;

        $gateway->updateQuote($quoteId, new QuoteUpdate(customFields: ['probe_fresh' => true]), $revision);

        self::assertTrue($gateway->fetchSnapshot($quoteId)->lifecycle->customFields['probe_fresh'] ?? false);
    }

    public function testWriteIsRefusedWithAStaleRevision(): void
    {
        $gateway = $this->gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());

        $stale = $gateway->fetchSnapshot($quoteId)->revision;

        // Move the quote so the captured revision goes stale.
        $gateway->updateQuote($quoteId, new QuoteUpdate(customFields: ['probe_mover' => true]));

        $this->expectException(QuoteRevisionMismatch::class);
        $gateway->updateQuote($quoteId, new QuoteUpdate(customFields: ['probe_stale' => true]), $stale);
    }

    private function gateway(): QuoteGatewayInterface
    {
        $gateway = static::getContainer()->get(QuoteGatewayInterface::class);
        self::assertInstanceOf(QuoteGatewayInterface::class, $gateway);

        return $gateway;
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `composer run test:integration`
Expected: `testWriteIsRefusedWithAStaleRevision` fails — `assertRevision` compares `updatedAt`, and whether the DAL bumps it on a customFields-only write is exactly what is unproven.

- [ ] **Step 3: Make the precondition real**

If the stale test failed because `updatedAt` did not change, the revision needs a field that does. In `QuoteSnapshotReader::readRevision()`, keep `versionId` and `updatedAt` but note in the class docblock which one actually moves. If neither moves reliably, the honest outcome is that `QuoteRevisionMismatch` cannot be enforced on this entity — in that case:

- Leave the `?QuoteRevision $expected` parameter in place (Servicing still passes it).
- Change `assertRevision()` to compare and throw only when it *can* detect movement.
- Add a `@todo` in the gateway naming the per-quote `symfony/lock` from the parent design as the real serialisation mechanism, and write that conclusion into the spec's Risks section.

Do not delete the test. Convert it to assert the observed behaviour with a docblock explaining why.

- [ ] **Step 4: Write the factory**

`src/Bridge/QuoteGatewayFactory.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability;

/**
 * The one place that decides whether a gateway exists at all. Returns null on a
 * shop without SwagCommercial or without the service-level license toggle;
 * callers degrade rather than fail, per ADR 0001.
 */
final readonly class QuoteGatewayFactory
{
    public function __construct(
        private QuoteSnapshotReader $reader,
        private QuoteWriters $writers,
    ) {}

    public function create(): ?QuoteGatewayInterface
    {
        if (!CommercialAvailability::isLicensed()) {
            return null;
        }

        return new SwagCommercialQuoteGateway($this->reader, $this->writers);
    }
}
```

- [ ] **Step 5: Wire DI**

In `src/Resources/config/services.php`, add — following the existing `autowire()->autoconfigure()` defaults and the `ignore-on-invalid` pattern for commercial services:

```php
    // Commercial services, resolved by string id because the classes need not
    // exist. ignoreOnInvalid() makes each reference null when absent, which is
    // what lets this plugin install on a shop without SwagCommercial.
    $services->set(QuoteSnapshotReader::class)
        ->args([service('quote.repository'), service(QuoteVersionResolver::class)]);

    $services->set(QuoteVersionResolver::class);
    $services->set(QuoteLineItemWriter::class)->args([service('quote_line_item.repository')]);
    $services->set(QuoteWriter::class)->args([service('quote.repository')]);
    $services->set(QuoteStateTransitioner::class);

    $services->set(SwagCommercialProductAdder::class)->args([
        service('Shopware\Commercial\B2B\QuoteManagement\Domain\Admin\QuoteManipulation')->ignoreOnInvalid(),
    ]);
    $services->set(SwagCommercialCommentWriter::class)->args([
        service('Shopware\Commercial\B2B\QuoteManagement\Domain\Comment\QuoteCommenter')->ignoreOnInvalid(),
    ]);
    $services->set(QuoteRecalculator::class)->args([
        service('Shopware\Commercial\B2B\QuoteManagement\Domain\SalesChannelContextRestorer\SalesChannelContextRestorer')->ignoreOnInvalid(),
        service('Shopware\Commercial\B2B\QuoteManagement\Domain\Recalculation\QuoteCalculator')->ignoreOnInvalid(),
    ]);

    $services->set(QuoteWriters::class);
    $services->set(QuoteGatewayFactory::class);
    $services->set(QuoteGatewayInterface::class)
        ->factory([service(QuoteGatewayFactory::class), 'create']);
```

Use the service ids confirmed working in Task 1 Step 7 — if they differed, use those instead.

- [ ] **Step 6: Write the wiring test**

`tests/Integration/GatewayWiringTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Bridge\SwagCommercialQuoteGateway;

final class GatewayWiringTest extends IntegrationTestCase
{
    public function testGatewayIsResolvableFromTheContainer(): void
    {
        $gateway = static::getContainer()->get(QuoteGatewayInterface::class);

        self::assertInstanceOf(SwagCommercialQuoteGateway::class, $gateway);
    }
}
```

- [ ] **Step 7: Run everything**

```bash
composer run test:integration
vendor/bin/phpunit
composer run format && composer run lint && composer run typecheck
composer run quality:filesize && composer run quality:dupes && composer run quality:depcheck && composer run quality:security
```
Expected: all green. Every earlier test's hand-rolled `gateway()` helper now resolves through DI — remove any temporary hand-construction added in Task 4 Step 3.

- [ ] **Step 8: Write the spike results into the spec**

Add a "Spike results (verified <date>)" section to `docs/superpowers/specs/2026-08-26-shopware-quote-bridge-design.md` recording, one line each:
1. Whether the repriced line survived `recalculate()` and what the custom-price flag did.
2. Tax-mode fidelity — net in, net out, or a factor applied.
3. Whether expiration must precede `sent`.
4. What authorship fields an agent comment gets.
5. Whether `customFields` merged or replaced.
6. What the revision precondition actually guarantees.
7. The working DI service ids for the commercial services.

Also update the spec's "Open question"/Risks text so it reflects answers rather than questions.

- [ ] **Step 9: Commit and open the PR**

```bash
vendor/bin/mago fmt tests/
git add -A
git commit -m "feat: wire the quote gateway through DI, record the spike results"
git push -u origin feat/shopware-bridge-quote-services
gh pr create --title "Shopware bridge over SwagCommercial quote services (issue #3)" --body "Closes #3"
```

---

## Self-Review

**Spec coverage:**

| Spec section | Task |
| --- | --- |
| Interface (7 methods, exact signatures) | 3 (declared), 4–9 (implemented) |
| `Bridge\Data` value objects | 2 |
| Versioning — `QuoteVersion` two lanes | 2 (enum), 4 (`QuoteVersionResolver`, snapshot-lane test) |
| `QuoteRevision` + write precondition | 2 (VO), 5/6 (`assertRevision`), 9 (proof + honest fallback) |
| Act chain / `customFields` shallow-merge | 6 |
| Custom-price constraint | 5 (write), 7 (survival proof) |
| Two `@internal` deps, listed in one place | 3 (adapter docblocks) |
| `QuoteState` dropped, core `StateMachineRegistry` used | 8 |
| `recalculate` needs `SalesChannelContextRestorer` | 7 |
| Runtime gate on `QUOTE_MANAGEMENT-6302947` | 3 (`CommercialAvailability`), 9 (factory + DI) |
| Snapshot ownership (bridge's own, not Policy's) | 2 (`QuoteSnapshot` docblock), 4 (reader) |
| Testing = the spike, live shop, not in CI | 1 (harness), 5–9 (findings), 9 Step 8 (write-back) |
| Non-goals (no order ops, no null gateway, no crypto) | Respected — no task implements them |

**Placeholder scan:** no TBD/TODO placeholders in plan steps. The one `@todo` mentioned in Task 9 Step 3 is a conditional code comment with named content, not a plan gap.

**Type consistency:** `QuoteGatewayInterface`'s seven signatures are declared once in Task 3 and reused verbatim in Tasks 4–9. `QuoteSnapshot`'s five grouped constructor params (Task 2) are what Task 4's reader builds and what Tasks 5–9's tests read (`->content->lines`, `->lifecycle->customFields`, `->identity->quoteId`, `->revision`, `->totals->totalNet`). `QuoteWriters` is introduced in Task 6 Step 4 as the param-count remedy and used by Tasks 7–9. `CommercialAvailability::isLicensed()` (Task 3) is what the factory calls (Task 9).

**Known soft spots, deliberately left as such:**
- Task 1 Step 7 and Task 4 Step 7 may need service-id or field-name correction against real SwagCommercial source. Both steps say so and name the file to read.
- Task 5's price test depends on Task 7's `recalculate`; the ordering is called out in both.
- `QuoteFixture` reads an existing quote rather than creating one. If the shop has none, Task 4 fails with an explicit message telling the operator to create one.
