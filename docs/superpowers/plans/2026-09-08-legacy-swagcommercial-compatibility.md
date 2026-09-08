# Legacy SwagCommercial Compatibility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the plugin install and service quotes on released SwagCommercial (6.7.1.2–6.7.12.x) as well as on unreleased trunk, so pilot customers exist.

**Architecture:** One injected `CommercialCapabilities` value object, built from DAL field introspection rather than version numbers, gates the seven places where trunk's quote schema differs from released. Nothing branches on a version string; every branch asks whether the field or route in front of it exists.

**Tech Stack:** PHP 8.3, Shopware 6.7 DAL, PHPUnit 11, mago (format/lint/analyze), Docker-hosted test shops.

**Spec:** `docs/superpowers/specs/2026-09-08-legacy-swagcommercial-compatibility-design.md`

## Global Constraints

- Support floor is **SwagCommercial 6.7.1.2**. Do not add branches for 6.7.0.x or 6.6.x.
- Detection is by **DAL field presence / class existence, never by version number**. No version string is parsed or compared anywhere in this plan.
- `CommercialAvailability` keeps its existing job (ADR 0001's class-and-licence gate, deciding *whether* the bridge is registered). `CommercialCapabilities` answers a different question (*what* the registered backend can do). Do not merge them.
- No SwagCommercial class may be named with `::class`. Commercial service ids stay string literals on `CommercialAvailability` (ADR 0001).
- Every new file goes through `composer run quality` before commit. `mago analyze` is strict; suppressions use `@mago-expect <rule>` with a prose reason, matching the style already in `src/Bridge`.
- The unit suite must not require a booted Shopware kernel. Anything needing a shop goes in `tests/Integration/`, which never runs in CI.
- Commit after every task. Branch: `spec/legacy-swagcommercial-compatibility`.

---

## File Structure

**Created:**
- `src/Bridge/Commercial/CommercialCapabilities.php` — the four booleans. Pure value object, no dependencies.
- `src/Bridge/Commercial/CommercialCapabilitiesFactory.php` — builds one from `DefinitionInstanceRegistry` + `class_exists`. The only place that knows *how* to detect.
- `tests/Unit/Bridge/Commercial/CommercialCapabilitiesTest.php`
- `tests/Unit/Bridge/QuoteLineNetTest.php`
- `tests/Unit/Bridge/QuoteLineMapperTest.php`
- `tests/Unit/Bridge/QuoteCommentMapperTest.php`
- `tests/Unit/Bridge/CommercialQuoteSnapshotMapperTest.php`
- `tests/Unit/Bridge/QuoteLineItemWriterTest.php`
- `tests/Unit/Policy/CommentTargetMergerLegacyStateTest.php`
- `tests/Unit/Servicing/QuoteServicingTriggerStatesTest.php`
- `tests/Integration/CapabilityProbeTest.php`
- `tests/Integration/LegacyBuyerFlowTest.php`
- `scripts/check-release-capabilities.php` — reads SwagCommercial git tags, asserts the probe's classification per release.
- `tests/Unit/ReleaseCapabilityMatrixTest.php` — runs that script's logic as a test when the clone is present, skips otherwise.

**Modified:**
- `src/Bridge/QuoteLineNet.php` — guard `requestedPrice`.
- `src/Bridge/QuoteLineMapper.php` — guard `deletedAt`; pass capabilities to `QuoteLineNet`.
- `src/Bridge/QuoteCommentMapper.php` — guard `quoteLineItemId`.
- `src/Bridge/QuoteSnapshotReader.php` — construct the three mappers with capabilities.
- `src/Bridge/CommercialQuoteSnapshotMapper.php` — guard `getRequestedPrice()`.
- `src/Bridge/QuoteLineItemWriter.php` — hard delete when soft delete is unavailable.
- `src/Bridge/SwagCommercialBuyerQuoteGateway.php` — drop send route from the all-or-nothing check; conditional send; 422 on legacy line prices.
- `src/Servicing/QuoteServicingTrigger.php` — widen `TRIGGER_STATES`.
- `src/Policy/CommentTargetMerger.php` — accept `reopen` as a renegotiation state.
- `src/Resources/config/services.php` — register the factory and thread capabilities into six services.
- `scripts/test-integration.sh` — document the second shop.

---

### Task 1: The capability value object and its factory

**Files:**
- Create: `src/Bridge/Commercial/CommercialCapabilities.php`
- Create: `src/Bridge/Commercial/CommercialCapabilitiesFactory.php`
- Test: `tests/Unit/Bridge/Commercial/CommercialCapabilitiesTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `CommercialCapabilities::__construct(bool $lineItemAsks, bool $softDeleteLines, bool $lineScopedComments, bool $draftBeforeSend)` — all four are public readonly properties.
  - `CommercialCapabilities::modern(): self` — all true. Test helper and the trunk default.
  - `CommercialCapabilities::legacy(): self` — all false.
  - `CommercialCapabilitiesFactory::__construct(DefinitionInstanceRegistry $registry)`
  - `CommercialCapabilitiesFactory::create(): CommercialCapabilities`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Bridge/Commercial/CommercialCapabilitiesTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge\Commercial;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
use PHPUnit\Framework\TestCase;

/**
 * The object is four booleans, so the only things worth pinning are the two
 * named constructors — they are what every other test in the suite builds its
 * fixtures from, and a silent flip in either would make a legacy test assert
 * modern behaviour while still passing.
 */
final class CommercialCapabilitiesTest extends TestCase
{
    public function testModernHasEveryCapability(): void
    {
        $capabilities = CommercialCapabilities::modern();

        self::assertTrue($capabilities->lineItemAsks);
        self::assertTrue($capabilities->softDeleteLines);
        self::assertTrue($capabilities->lineScopedComments);
        self::assertTrue($capabilities->draftBeforeSend);
    }

    public function testLegacyHasNone(): void
    {
        $capabilities = CommercialCapabilities::legacy();

        self::assertFalse($capabilities->lineItemAsks);
        self::assertFalse($capabilities->softDeleteLines);
        self::assertFalse($capabilities->lineScopedComments);
        self::assertFalse($capabilities->draftBeforeSend);
    }

    public function testCapabilitiesAreIndependent(): void
    {
        $capabilities = new CommercialCapabilities(
            lineItemAsks: false,
            softDeleteLines: true,
            lineScopedComments: false,
            draftBeforeSend: true,
        );

        self::assertFalse($capabilities->lineItemAsks);
        self::assertTrue($capabilities->softDeleteLines);
        self::assertFalse($capabilities->lineScopedComments);
        self::assertTrue($capabilities->draftBeforeSend);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter CommercialCapabilitiesTest`
Expected: FAIL — `Class "MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities" not found`.

- [ ] **Step 3: Write the value object**

Create `src/Bridge/Commercial/CommercialCapabilities.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Commercial;

/**
 * What the SwagCommercial in front of us can do, as opposed to whether it is
 * there at all — that second question is {@see CommercialAvailability}'s, and
 * the two stay separate because they gate different things: availability
 * decides whether the bridge is registered, this decides how it behaves once
 * it is.
 *
 * Released SwagCommercial (6.7.1.2 through 6.7.12.x) has none of these four;
 * trunk has all four. They are separate booleans rather than one `legacy` flag
 * because SwagCommercial backports schema into patch releases — `quote.cart_payload`
 * landed in 6.7.9, mid-line — so a shop holding some of them and not others is
 * a state that will occur, and the first candidate for a backport is
 * `requested_price`, the very field blocking these pilots.
 */
final readonly class CommercialCapabilities
{
    public function __construct(
        /** `quote_line_item.requestedPrice`: the buyer's own per-unit ask. */
        public bool $lineItemAsks,
        /** `quote_line_item.deletedAt`: removal is a soft delete, not a delete. */
        public bool $softDeleteLines,
        /** `quote_comment.quoteLineItemId`: a comment can be scoped to one line. */
        public bool $lineScopedComments,
        /** A quote request creates a draft that a second route then sends. */
        public bool $draftBeforeSend,
    ) {}

    /** Trunk, and any release that has caught up with it. */
    public static function modern(): self
    {
        return new self(
            lineItemAsks: true,
            softDeleteLines: true,
            lineScopedComments: true,
            draftBeforeSend: true,
        );
    }

    /** Released SwagCommercial through 6.7.12.x. */
    public static function legacy(): self
    {
        return new self(
            lineItemAsks: false,
            softDeleteLines: false,
            lineScopedComments: false,
            draftBeforeSend: false,
        );
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter CommercialCapabilitiesTest`
Expected: PASS, 3 tests.

- [ ] **Step 5: Write the factory**

There is no unit test for the factory: it reads the live DAL registry, which needs a booted kernel. `CapabilityProbeTest` (Task 9) covers it against real shops. Create `src/Bridge/Commercial/CommercialCapabilitiesFactory.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Commercial;

use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;

/**
 * Builds {@see CommercialCapabilities} by asking the DAL what this shop's
 * SwagCommercial actually declares.
 *
 * Field presence, never a version number. SwagCommercial ships schema in patch
 * releases, so a version comparison would be wrong the first time somebody
 * backports `requested_price` — and the DAL definition is the same source the
 * read path will hit a microsecond later, so there is no window where the two
 * can disagree.
 *
 * `has()` on the registry before `getByEntityName()`: the latter throws on an
 * unknown entity, and a shop with SwagCommercial inactive has no `quote`
 * entity at all. That cannot happen behind `services.php`'s availability guard,
 * but a factory that throws during container warmup takes the whole shop down,
 * so it degrades to "no capabilities" instead.
 */
final readonly class CommercialCapabilitiesFactory
{
    private const QUOTE_LINE_ITEM = 'quote_line_item';

    private const QUOTE_COMMENT = 'quote_comment';

    public function __construct(
        private DefinitionInstanceRegistry $registry,
    ) {}

    public function create(): CommercialCapabilities
    {
        return new CommercialCapabilities(
            lineItemAsks: $this->hasField(self::QUOTE_LINE_ITEM, 'requestedPrice'),
            softDeleteLines: $this->hasField(self::QUOTE_LINE_ITEM, 'deletedAt'),
            lineScopedComments: $this->hasField(self::QUOTE_COMMENT, 'quoteLineItemId'),
            draftBeforeSend: class_exists(CommercialAvailability::QUOTE_SEND_REQUEST_ROUTE),
        );
    }

    private function hasField(string $entityName, string $propertyName): bool
    {
        if (!$this->registry->has($entityName)) {
            return false;
        }

        return $this->registry->getByEntityName($entityName)->getFields()->get($propertyName) !== null;
    }
}
```

- [ ] **Step 6: Register both in the container**

In `src/Resources/config/services.php`, add the imports beside the other `Bridge\Commercial` imports (near line 13):

```php
use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilitiesFactory;
```

Then, immediately after the `if (!CommercialAvailability::isAvailableByClass()) { return; }` guard (around line 395), before `$services->set(QuoteVersionResolver::class);`:

```php
    // What this shop's SwagCommercial can do, probed once at container build
    // from the DAL rather than from a version number (see the factory). Six
    // services below take it; nothing outside Bridge does.
    $services->set(CommercialCapabilitiesFactory::class)->args([service(DefinitionInstanceRegistry::class)]);
    $services->set(CommercialCapabilities::class)->factory([
        service(CommercialCapabilitiesFactory::class),
        'create',
    ]);
```

Add the registry import at the top of the file if absent:

```php
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
```

- [ ] **Step 7: Run quality gates**

Run: `composer run quality && vendor/bin/phpunit`
Expected: all green.

- [ ] **Step 8: Commit**

```bash
git add src/Bridge/Commercial/CommercialCapabilities.php \
        src/Bridge/Commercial/CommercialCapabilitiesFactory.php \
        tests/Unit/Bridge/Commercial/CommercialCapabilitiesTest.php \
        src/Resources/config/services.php
git commit -m "feat(bridge): probe what this shop's SwagCommercial can do"
```

---

### Task 2: Guard the buyer's per-line ask in QuoteLineNet

**Files:**
- Modify: `src/Bridge/QuoteLineNet.php:38`
- Test: `tests/Unit/Bridge/QuoteLineNetTest.php`

**Interfaces:**
- Consumes: `CommercialCapabilities` from Task 1.
- Produces: `QuoteLineNet::of(Entity $lineItem, string $taxStatus, CommercialCapabilities $capabilities): self` — the third parameter is new and required. `QuoteLineMapper` (Task 3) is the only caller.

- [ ] **Step 1: Write the failing test**

`ArrayEntity` is core's generic `Entity` subclass, which lets a test build a line item without SwagCommercial. Create `tests/Unit/Bridge/QuoteLineNetTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
use MerchantQuoteAgentPlugin\Bridge\QuoteLineNet;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\Struct\ArrayEntity;

/**
 * On released SwagCommercial there is no `requested_price` column, and
 * `Entity::get()` throws `propertyNotFound` rather than returning null — so the
 * capability flag is what keeps a legacy shop from taking down every servicing
 * pass on its first quote.
 */
final class QuoteLineNetTest extends TestCase
{
    public function testAModernShopReadsTheBuyersAsk(): void
    {
        $net = QuoteLineNet::of(
            self::lineItem(['requestedPrice' => 8.0]),
            CartPrice::TAX_STATE_NET,
            CommercialCapabilities::modern(),
        );

        self::assertSame(8.0, $net->requestedUnitPrice);
    }

    public function testALegacyShopReportsNoAskAndDoesNotTouchTheField(): void
    {
        // No `requestedPrice` key at all: reading it would throw, which is
        // exactly the production failure this guards.
        $net = QuoteLineNet::of(
            self::lineItem([]),
            CartPrice::TAX_STATE_NET,
            CommercialCapabilities::legacy(),
        );

        self::assertNull($net->requestedUnitPrice);
    }

    public function testALegacyShopStillDerivesUnitAndTotalNet(): void
    {
        $net = QuoteLineNet::of(
            self::lineItem([]),
            CartPrice::TAX_STATE_NET,
            CommercialCapabilities::legacy(),
        );

        self::assertSame(100.0, $net->total);
        self::assertSame(25.0, $net->unitPrice);
    }

    /** @param array<string, mixed> $extra */
    private static function lineItem(array $extra): Entity
    {
        return new ArrayEntity(['totalPrice' => 100.0, 'quantity' => 4, 'price' => null, ...$extra]);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter QuoteLineNetTest`
Expected: FAIL — `of()` takes 2 arguments, 3 given.

- [ ] **Step 3: Add the guard**

In `src/Bridge/QuoteLineNet.php`, add the import:

```php
use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
```

Change the signature and the one read. Replace:

```php
    /** @param string $taxStatus the owning quote's `taxStatus`; only `gross` needs converting */
    public static function of(Entity $lineItem, string $taxStatus): self
    {
        $total = (float) $lineItem->get('totalPrice');
        $totalNet = round($total - self::taxIn($lineItem, $taxStatus), precision: 2);
        $requested = $lineItem->get('requestedPrice');
```

with:

```php
    /**
     * @param string $taxStatus the owning quote's `taxStatus`; only `gross` needs converting
     * @param CommercialCapabilities $capabilities `requested_price` is a trunk
     *     column; on a released SwagCommercial `Entity::get()` throws
     *     `propertyNotFound` rather than returning null, so the read has to be
     *     gated rather than defaulted
     */
    public static function of(Entity $lineItem, string $taxStatus, CommercialCapabilities $capabilities): self
    {
        $total = (float) $lineItem->get('totalPrice');
        $totalNet = round($total - self::taxIn($lineItem, $taxStatus), precision: 2);
        $requested = $capabilities->lineItemAsks ? $lineItem->get('requestedPrice') : null;
```

Then extend the class docblock with a sentence after the existing paragraphs:

```php
 * On a SwagCommercial without the `requested_price` column the buyer has no way
 * to state a per-line ask at all, so `requestedUnitPrice` is null on every line
 * and the ask arrives through comment prose instead — which is what
 * CommentTargetMerger reads.
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter QuoteLineNetTest`
Expected: PASS, 3 tests. `QuoteLineMapper` will not compile-error, but its own tests arrive in Task 3; run `composer run typecheck` and expect one complaint about the missing argument at `QuoteLineMapper.php:36`, which Task 3 fixes.

- [ ] **Step 5: Commit**

```bash
git add src/Bridge/QuoteLineNet.php tests/Unit/Bridge/QuoteLineNetTest.php
git commit -m "fix(bridge): only read requested_price where the column exists"
```

---

### Task 3: Guard soft-delete and thread capabilities through the read mappers

**Files:**
- Modify: `src/Bridge/QuoteLineMapper.php`
- Modify: `src/Bridge/QuoteCommentMapper.php`
- Modify: `src/Bridge/QuoteSnapshotReader.php:36-41`
- Test: `tests/Unit/Bridge/QuoteLineMapperTest.php`
- Test: `tests/Unit/Bridge/QuoteCommentMapperTest.php`

**Interfaces:**
- Consumes: `CommercialCapabilities` (Task 1), `QuoteLineNet::of(Entity, string, CommercialCapabilities)` (Task 2).
- Produces:
  - `QuoteLineMapper::__construct(CommercialCapabilities $capabilities)` — was parameterless.
  - `QuoteCommentMapper::__construct(CommercialCapabilities $capabilities)` — was parameterless.
  - `QuoteSnapshotReader::__construct(EntityRepository $quoteRepository, QuoteVersionResolver $versionResolver, CommercialCapabilities $capabilities)` — third parameter is new.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Bridge/QuoteLineMapperTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
use MerchantQuoteAgentPlugin\Bridge\QuoteLineMapper;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\Struct\ArrayEntity;

/**
 * Two trunk-only columns meet here: `deletedAt`, which decides whether a line
 * is skipped, and `requestedPrice`, read through QuoteLineNet. Neither exists on
 * a released SwagCommercial, and `Entity::get()` throws on both.
 */
final class QuoteLineMapperTest extends TestCase
{
    public function testAModernShopSkipsSoftDeletedLines(): void
    {
        $lines = (new QuoteLineMapper(CommercialCapabilities::modern()))->map(self::quote([
            self::line('live', ['deletedAt' => null, 'requestedPrice' => null]),
            self::line('gone', ['deletedAt' => new \DateTimeImmutable(), 'requestedPrice' => null]),
        ]));

        self::assertCount(1, $lines);
        self::assertSame('live', $lines[0]->identity->lineItemId);
    }

    public function testALegacyShopKeepsEveryLineAndNeverReadsDeletedAt(): void
    {
        // Neither `deletedAt` nor `requestedPrice` is present: on a released
        // shop those columns do not exist and reading either throws.
        $lines = (new QuoteLineMapper(CommercialCapabilities::legacy()))->map(self::quote([
            self::line('one', []),
            self::line('two', []),
        ]));

        self::assertCount(2, $lines);
        self::assertNull($lines[0]->requestedUnitPrice);
    }

    /** @param list<Entity> $lines */
    private static function quote(array $lines): Entity
    {
        return new ArrayEntity(['taxStatus' => CartPrice::TAX_STATE_NET, 'lineItems' => $lines]);
    }

    /** @param array<string, mixed> $extra */
    private static function line(string $id, array $extra): Entity
    {
        return new ArrayEntity([
            'id' => $id,
            'label' => 'Widget',
            'referencedId' => 'product-1',
            'quantity' => 2,
            'totalPrice' => 20.0,
            'price' => null,
            ...$extra,
        ]);
    }
}
```

Create `tests/Unit/Bridge/QuoteCommentMapperTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
use MerchantQuoteAgentPlugin\Bridge\QuoteCommentMapper;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\Struct\ArrayEntity;

/**
 * `quote_comment.quote_line_item_id` is trunk-only. The comment itself is the
 * one thing a legacy shop must never lose, because there it is the buyer's only
 * ask channel — so the guard drops the scoping, not the text.
 */
final class QuoteCommentMapperTest extends TestCase
{
    public function testAModernShopReadsTheLineScope(): void
    {
        $comments = (new QuoteCommentMapper(CommercialCapabilities::modern()))->map(self::quote([
            self::comment(['quoteLineItemId' => 'line-1']),
        ]));

        self::assertSame('line-1', $comments[0]->lineItemId);
    }

    public function testALegacyShopKeepsTheTextAndReportsNoLineScope(): void
    {
        $comments = (new QuoteCommentMapper(CommercialCapabilities::legacy()))->map(self::quote([
            self::comment([]),
        ]));

        self::assertCount(1, $comments);
        self::assertSame('Can you do better on price?', $comments[0]->comment);
        self::assertNull($comments[0]->lineItemId);
    }

    /** @param list<Entity> $comments */
    private static function quote(array $comments): Entity
    {
        return new ArrayEntity(['comments' => $comments]);
    }

    /** @param array<string, mixed> $extra */
    private static function comment(array $extra): Entity
    {
        return new ArrayEntity([
            'comment' => 'Can you do better on price?',
            'createdById' => null,
            'customerId' => 'customer-1',
            'createdAt' => null,
            'employeeId' => null,
            ...$extra,
        ]);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter 'QuoteLineMapperTest|QuoteCommentMapperTest'`
Expected: FAIL — both mappers take 0 constructor arguments, 1 given.

- [ ] **Step 3: Add the constructors and guards**

In `src/Bridge/QuoteLineMapper.php`, add the import and constructor, and gate both reads. Replace the class body opening:

```php
final class QuoteLineMapper
{
    /** @return list<QuoteLineSnapshot> */
    public function map(Entity $quote): array
    {
```

with:

```php
final readonly class QuoteLineMapper
{
    public function __construct(
        private CommercialCapabilities $capabilities,
    ) {}

    /** @return list<QuoteLineSnapshot> */
    public function map(Entity $quote): array
    {
```

Add at the top:

```php
use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
```

Replace the loop body:

```php
        foreach ($lineItems as $lineItem) {
            if (!$lineItem instanceof Entity || $lineItem->get('deletedAt') !== null) {
                continue;
            }

            $lines[] = $this->line($lineItem, QuoteLineNet::of($lineItem, $taxStatus));
        }
```

with:

```php
        foreach ($lineItems as $lineItem) {
            if (!$lineItem instanceof Entity || $this->isRemoved($lineItem)) {
                continue;
            }

            $lines[] = $this->line($lineItem, QuoteLineNet::of($lineItem, $taxStatus, $this->capabilities));
        }
```

and add the helper beside `nullableString()`:

```php
    /**
     * A released SwagCommercial has no `deleted_at` on a quote line — removal
     * there is a real delete, so a row that is present is a live line and
     * `Entity::get()` would throw on the column rather than return null.
     */
    private function isRemoved(Entity $lineItem): bool
    {
        return $this->capabilities->softDeleteLines && $lineItem->get('deletedAt') !== null;
    }
```

In `src/Bridge/QuoteCommentMapper.php`, add the same import, make the class `final readonly`, add the constructor, and replace the `lineItemId` argument:

```php
                lineItemId: $this->capabilities->lineScopedComments
                    ? $this->nullableString($comment->get('quoteLineItemId'))
                    : null,
```

- [ ] **Step 4: Thread capabilities through the reader**

In `src/Bridge/QuoteSnapshotReader.php`, add the import and replace the constructor:

```php
    /** @param EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $quoteRepository */
    public function __construct(
        private EntityRepository $quoteRepository,
        private QuoteVersionResolver $versionResolver,
        CommercialCapabilities $capabilities,
    ) {
        $this->lineMapper = new QuoteLineMapper($capabilities);
        $this->commentMapper = new QuoteCommentMapper($capabilities);
        $this->discountMapper = new QuoteDiscountMapper();
    }
```

`QuoteDiscountMapper` is untouched: `quote.discount` exists on every supported release.

In `src/Resources/config/services.php`, add the third argument to `QuoteSnapshotReader` (around line 399):

```php
    $services->set(QuoteSnapshotReader::class)->args([
        service('quote.repository'),
        service(QuoteVersionResolver::class),
        service(CommercialCapabilities::class),
    ]);
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit && composer run typecheck`
Expected: PASS. The `QuoteLineNet::of()` arity complaint from Task 2 is now resolved.

- [ ] **Step 6: Commit**

```bash
git add src/Bridge/QuoteLineMapper.php src/Bridge/QuoteCommentMapper.php \
        src/Bridge/QuoteSnapshotReader.php src/Resources/config/services.php \
        tests/Unit/Bridge/QuoteLineMapperTest.php tests/Unit/Bridge/QuoteCommentMapperTest.php
git commit -m "fix(bridge): read trunk-only quote columns only where they exist"
```

---

### Task 4: Guard getRequestedPrice() in the buyer-facing snapshot mapper

**Files:**
- Modify: `src/Bridge/CommercialQuoteSnapshotMapper.php:69`
- Test: `tests/Unit/Bridge/CommercialQuoteSnapshotMapperTest.php`

**Interfaces:**
- Consumes: `CommercialCapabilities` (Task 1).
- Produces: `CommercialQuoteSnapshotMapper::__construct(CommercialCapabilities $capabilities)` — was parameterless.

This is the breakage that kills the entire buyer surface on legacy, and it is a *method* call, not a field read: `Error: Call to undefined method` on `getQuote`, `listQuotes`, and the snapshot every mutating call returns.

- [ ] **Step 1: Write the failing test**

The mapper takes an untyped `object`, so a test can pass an anonymous class with exactly the methods a given SwagCommercial release has. Create `tests/Unit/Bridge/CommercialQuoteSnapshotMapperTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
use MerchantQuoteAgentPlugin\Bridge\CommercialQuoteSnapshotMapper;
use PHPUnit\Framework\TestCase;

/**
 * The mapper reads an untyped SwagCommercial entity, so a released shop differs
 * from trunk by a method that is simply not there. The fixtures below are the
 * two entity shapes: one with `getRequestedPrice()`, one without. Calling the
 * absent one is a fatal `Error`, not a warning, and it fires on every
 * buyer-side read — which is why this is gated rather than defaulted.
 */
final class CommercialQuoteSnapshotMapperTest extends TestCase
{
    public function testAModernShopPublishesTheBuyersAsk(): void
    {
        $snapshot = (new CommercialQuoteSnapshotMapper(CommercialCapabilities::modern()))
            ->toSnapshot(self::quote(self::modernLine()));

        self::assertSame(7.5, $snapshot->lineItems[0]['requested_unit_price']);
    }

    public function testALegacyShopPublishesNullWithoutCallingTheMissingGetter(): void
    {
        $snapshot = (new CommercialQuoteSnapshotMapper(CommercialCapabilities::legacy()))
            ->toSnapshot(self::quote(self::legacyLine()));

        self::assertNull($snapshot->lineItems[0]['requested_unit_price']);
        self::assertSame('line-1', $snapshot->lineItems[0]['id']);
        self::assertSame(12.0, $snapshot->lineItems[0]['unit_price']);
    }

    private static function legacyLine(): object
    {
        return new class {
            public function getId(): string { return 'line-1'; }

            public function getProductId(): ?string { return 'product-1'; }

            public function getLabel(): string { return 'Widget'; }

            public function getQuantity(): int { return 3; }

            public function getUnitPrice(): float { return 12.0; }

            public function getTotalPrice(): float { return 36.0; }
        };
    }

    private static function modernLine(): object
    {
        return new class {
            public function getId(): string { return 'line-1'; }

            public function getProductId(): ?string { return 'product-1'; }

            public function getLabel(): string { return 'Widget'; }

            public function getQuantity(): int { return 3; }

            public function getUnitPrice(): float { return 12.0; }

            public function getTotalPrice(): float { return 36.0; }

            public function getRequestedPrice(): ?float { return 7.5; }
        };
    }

    private static function quote(object $line): object
    {
        return new class ($line) {
            public function __construct(private readonly object $line) {}

            public function getId(): string { return 'quote-1'; }

            public function getQuoteNumber(): string { return '10001'; }

            public function getStateMachineState(): ?object { return null; }

            public function getExpirationDate(): ?\DateTimeInterface { return null; }

            public function getCurrency(): ?object { return null; }

            public function getAmountTotal(): float { return 36.0; }

            public function getAmountNet(): float { return 30.25; }

            public function getTaxStatus(): string { return 'gross'; }

            /** @return list<object> */
            public function getLineItems(): array { return [$this->line]; }

            /** @return list<object> */
            public function getComments(): array { return []; }
        };
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter CommercialQuoteSnapshotMapperTest`
Expected: FAIL — the mapper takes 0 constructor arguments, 1 given. (Were the constructor already there, the legacy case would fail with `Call to undefined method ...::getRequestedPrice()`, which is the production bug.)

- [ ] **Step 3: Add the constructor and the guard**

In `src/Bridge/CommercialQuoteSnapshotMapper.php`, add the import:

```php
use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
```

Add the constructor at the top of the class body:

```php
    public function __construct(
        private CommercialCapabilities $capabilities,
    ) {}
```

Replace the one line:

```php
                'requested_unit_price' => $lineItem->getRequestedPrice(),
```

with:

```php
                // A method, not a column: on a SwagCommercial without line-item
                // asks this getter does not exist, and calling it is a fatal
                // Error on every buyer-side read rather than a null.
                'requested_unit_price' => $this->capabilities->lineItemAsks
                    ? $lineItem->getRequestedPrice()
                    : null,
```

- [ ] **Step 4: Register the argument**

In `src/Resources/config/services.php`, replace line 449:

```php
    $services->set(CommercialQuoteSnapshotMapper::class)->args([service(CommercialCapabilities::class)]);
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter CommercialQuoteSnapshotMapperTest && composer run quality`
Expected: PASS, 2 tests; quality green.

- [ ] **Step 6: Commit**

```bash
git add src/Bridge/CommercialQuoteSnapshotMapper.php src/Resources/config/services.php \
        tests/Unit/Bridge/CommercialQuoteSnapshotMapperTest.php
git commit -m "fix(bridge): do not call getRequestedPrice where it does not exist"
```

---

### Task 5: Hard-delete quote lines where soft delete is unavailable

**Files:**
- Modify: `src/Bridge/QuoteLineItemWriter.php`
- Test: `tests/Unit/Bridge/QuoteLineItemWriterTest.php`

**Interfaces:**
- Consumes: `CommercialCapabilities` (Task 1).
- Produces: `QuoteLineItemWriter::__construct(EntityRepository $lineItemRepository, CommercialCapabilities $capabilities)` — second parameter is new. `write()` keeps its signature.

Repricing is deliberately untouched. On 6.7.12 `SKIP_PRODUCT_RECALCULATION` is set unconditionally by `ADMIN_EDIT_QUOTE_PERMISSIONS`, so the existing `priceDefinition` write already survives recalculation there; the `quote_custom_offer_price` flag it also writes is simply inert.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Bridge/QuoteLineItemWriterTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineItemChange;
use MerchantQuoteAgentPlugin\Bridge\QuoteLineItemWriter;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

/**
 * The DAL rejects an unknown field outright, so a `deletedAt` write against a
 * released SwagCommercial is not a silent no-op — it throws. The two profiles
 * therefore issue genuinely different operations, which is what these assert.
 *
 * The repository is a mock rather than a live one because the assertion is
 * about which operation is issued with what payload; QuoteLineItemWriteTest in
 * the integration suite covers that the operation actually lands.
 */
final class QuoteLineItemWriterTest extends TestCase
{
    public function testAModernShopSoftDeletesThroughUpdate(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())->method('update')->with(self::callback(
            static function (array $payload): bool {
                self::assertCount(1, $payload);
                self::assertSame('line-1', $payload[0]['id']);
                self::assertArrayHasKey('deletedAt', $payload[0]);

                return true;
            },
        ));
        $repository->expects(self::never())->method('delete');

        (new QuoteLineItemWriter($repository, CommercialCapabilities::modern()))
            ->write([new QuoteLineItemChange(lineItemId: 'line-1', remove: true)], Context::createDefaultContext());
    }

    public function testALegacyShopDeletesTheRow(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::never())->method('update');
        $repository->expects(self::once())->method('delete')->with([['id' => 'line-1']]);

        (new QuoteLineItemWriter($repository, CommercialCapabilities::legacy()))
            ->write([new QuoteLineItemChange(lineItemId: 'line-1', remove: true)], Context::createDefaultContext());
    }

    public function testALegacyShopStillBatchesQuantityChangesIntoUpdate(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())->method('update')->with([['id' => 'line-2', 'quantity' => 5]]);
        $repository->expects(self::once())->method('delete')->with([['id' => 'line-1']]);

        (new QuoteLineItemWriter($repository, CommercialCapabilities::legacy()))->write([
            new QuoteLineItemChange(lineItemId: 'line-1', remove: true),
            new QuoteLineItemChange(lineItemId: 'line-2', quantity: 5),
        ], Context::createDefaultContext());
    }

    public function testNothingIsIssuedForAnEmptyChangeSet(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::never())->method('update');
        $repository->expects(self::never())->method('delete');

        (new QuoteLineItemWriter($repository, CommercialCapabilities::legacy()))
            ->write([], Context::createDefaultContext());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter QuoteLineItemWriterTest`
Expected: FAIL — the writer takes 1 constructor argument, 2 given.

- [ ] **Step 3: Split the payload**

In `src/Bridge/QuoteLineItemWriter.php`, add the import:

```php
use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
```

Replace the constructor:

```php
    /** @param EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $lineItemRepository */
    public function __construct(
        private EntityRepository $lineItemRepository,
        private CommercialCapabilities $capabilities,
    ) {
        $this->taxRules = new QuoteLineTaxRules($lineItemRepository);
    }
```

Replace `write()`:

```php
    /** @param list<QuoteLineItemChange> $changes */
    public function write(array $changes, Context $context): void
    {
        $taxRules = $this->taxRules->forLines($this->repricedIds($changes), $context);
        $payload = [];
        $deletions = [];

        foreach ($changes as $change) {
            if ($change->isRemoval() && !$this->capabilities->softDeleteLines) {
                $deletions[] = ['id' => $change->lineItemId];

                continue;
            }

            $row = $this->rowFor($change, $taxRules);
            if ($row !== []) {
                $payload[] = ['id' => $change->lineItemId, ...$row];
            }
        }

        if ($payload !== []) {
            $this->lineItemRepository->update($payload, $context);
        }

        if ($deletions !== []) {
            $this->lineItemRepository->delete($deletions, $context);
        }
    }
```

Then extend the class docblock, after the paragraph about `isCalculated`:

```php
 * Removal is the one operation that differs by SwagCommercial version. Trunk
 * added `quote_line_item.deleted_at` and its transformer skips those rows; a
 * released SwagCommercial has no such column, and the DAL rejects an unknown
 * field outright rather than ignoring it — so there, removal is a real delete.
 * That loses the audit trail the soft-delete model preserves, which is accepted:
 * a released shop has nowhere to preserve it, and refusing to remove lines would
 * block a concession the agent is otherwise authorized to make. The A2CN act
 * chain lives in `quote.customFields` and is unaffected either way.
```

- [ ] **Step 4: Register the argument**

In `src/Resources/config/services.php`, replace line 403:

```php
    $services->set(QuoteLineItemWriter::class)->args([
        service('quote_line_item.repository'),
        service(CommercialCapabilities::class),
    ]);
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter QuoteLineItemWriterTest && composer run quality`
Expected: PASS, 4 tests; quality green.

- [ ] **Step 6: Commit**

```bash
git add src/Bridge/QuoteLineItemWriter.php src/Resources/config/services.php \
        tests/Unit/Bridge/QuoteLineItemWriterTest.php
git commit -m "fix(bridge): delete quote lines where soft delete does not exist"
```

---

### Task 6: Recognise `reopen` as a renegotiation state

**Files:**
- Modify: `src/Servicing/QuoteServicingTrigger.php:42`
- Modify: `src/Policy/CommentTargetMerger.php:34` and its class docblock
- Test: `tests/Unit/Servicing/QuoteServicingTriggerStatesTest.php`
- Test: `tests/Unit/Policy/CommentTargetMergerLegacyStateTest.php`

**Interfaces:**
- Consumes: nothing. This is a plain widening, deliberately **not** capability-gated: on trunk no route transitions into `reopen`, and if one ever did, servicing a reopened quote is correct.
- Produces: no signature changes.

On a released SwagCommercial a buyer's change request runs `ACTION_REQUEST_CHANGE` into **`reopen`** and posts the text as a comment. Both literals below currently name only trunk's `change_requested`. The `CommentTargetMerger` one is the dangerous half: on legacy the comment is the *only* ask channel, so leaving it would discard every buyer ask without erroring.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Servicing/QuoteServicingTriggerStatesTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Servicing\QuoteServicingTrigger;
use PHPUnit\Framework\TestCase;

/**
 * The trigger states are a private constant, so this reads them reflectively
 * rather than driving a state-change event: the assertion is about which state
 * names the plugin recognises across SwagCommercial versions, and that is
 * exactly what the constant is.
 *
 * `in_review` and `replied` must stay out — those are the states the agent's own
 * servicing drives, and their absence is what makes a self-trigger impossible by
 * construction.
 */
final class QuoteServicingTriggerStatesTest extends TestCase
{
    public function testBothRenegotiationStateNamesTrigger(): void
    {
        $states = self::triggerStates();

        self::assertContains('open', $states);
        self::assertContains('change_requested', $states, 'trunk names it this');
        self::assertContains('reopen', $states, 'released SwagCommercial names it this');
    }

    public function testTheAgentsOwnStatesNeverTrigger(): void
    {
        $states = self::triggerStates();

        self::assertNotContains('in_review', $states);
        self::assertNotContains('replied', $states);
    }

    /** @return list<string> */
    private static function triggerStates(): array
    {
        /** @var list<string> $states */
        $states = (new \ReflectionClass(QuoteServicingTrigger::class))->getConstant('TRIGGER_STATES');

        return $states;
    }
}
```

Create `tests/Unit/Policy/CommentTargetMergerLegacyStateTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy;

use MerchantQuoteAgentPlugin\Policy\CommentTargetMerger;
use PHPUnit\Framework\TestCase;

/**
 * On a released SwagCommercial a renegotiation round sits in `reopen`, not
 * `change_requested`, and there is no structured `requested_price` at all — so
 * the comment target is the only ask the agent will ever see. If the state name
 * is not recognised, `$commentWins` is false and a stale structured ask would
 * outrank it. On legacy there is no structured ask to be stale, so the visible
 * symptom would be a modern shop mid-migration, not an outright failure — which
 * is precisely why it needs a test rather than a code read.
 *
 * Build the snapshot and interpretation with the same helpers the existing
 * CommentTargetMergerTest uses; this file only adds the state-name cases.
 */
final class CommentTargetMergerLegacyStateTest extends TestCase
{
    public function testACommentTargetWinsInReopen(): void
    {
        $merged = (new CommentTargetMerger())->merge(
            self::snapshotInState('reopen', structuredAsk: 9.0),
            self::interpretationTargeting('line-1', 7.0),
        );

        self::assertSame(7.0, $merged->lines[0]->requestedUnitPrice);
    }

    public function testACommentTargetStillWinsInChangeRequested(): void
    {
        $merged = (new CommentTargetMerger())->merge(
            self::snapshotInState('change_requested', structuredAsk: 9.0),
            self::interpretationTargeting('line-1', 7.0),
        );

        self::assertSame(7.0, $merged->lines[0]->requestedUnitPrice);
    }

    public function testAStructuredAskStillWinsOutsideARenegotiationRound(): void
    {
        $merged = (new CommentTargetMerger())->merge(
            self::snapshotInState('open', structuredAsk: 9.0),
            self::interpretationTargeting('line-1', 7.0),
        );

        self::assertSame(9.0, $merged->lines[0]->requestedUnitPrice);
    }
}
```

**Before writing the two private helpers**, read `tests/Unit/Policy/CommentTargetMergerTest.php` and copy its existing snapshot/interpretation builders, adapting them to take a state name and a structured ask. Do not invent a new fixture shape — `Policy\Data\QuoteSnapshot` and `CommentInterpretation` have exact constructors that file already exercises.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter 'QuoteServicingTriggerStatesTest|CommentTargetMergerLegacyStateTest'`
Expected: FAIL — `reopen` is not in `TRIGGER_STATES`, and `testACommentTargetWinsInReopen` asserts 7.0 but gets 9.0.

- [ ] **Step 3: Widen both**

In `src/Servicing/QuoteServicingTrigger.php`, replace the constant and extend its docblock:

```php
    /**
     * The only states that mean the agent has something to do. Notably NOT
     * `in_review` or `replied`: those are the states the agent's own servicing
     * drives, so leaving them out means a self-trigger is impossible by
     * construction, independently of the context stamp.
     *
     * `change_requested` and `reopen` are the same event under two
     * SwagCommercial versions — a buyer asking for changes. Trunk added
     * `change_requested`; a released SwagCommercial runs `ACTION_REQUEST_CHANGE`
     * into `reopen`. Both are listed unconditionally rather than probed: on
     * trunk no route transitions into `reopen`, and if one ever did, servicing a
     * reopened quote is the right answer anyway.
     */
    private const TRIGGER_STATES = ['open', 'change_requested', 'reopen'];
```

In `src/Policy/CommentTargetMerger.php`, replace the docblock's first paragraph:

```php
/**
 * Per-line target prices asked in comments behave exactly like the structured
 * "Requested price" field; the structured field wins unless this is a
 * renegotiation round, where the newer comment ask wins.
 *
 * A renegotiation round is `change_requested` on trunk and `reopen` on a
 * released SwagCommercial. Recognising both matters most on the older one,
 * where there is no structured field at all and the comment is the buyer's only
 * ask channel.
 *
 * Ported from `mergeCommentTargets` in src/policy/quote-decision.ts.
 */
```

and the line itself:

```php
        $commentWins = \in_array(
            $snapshot->lifecycle->stateTechnicalName,
            self::RENEGOTIATION_STATES,
            strict: true,
        );
```

with the constant added at the top of the class body:

```php
    /** `change_requested` on trunk, `reopen` on a released SwagCommercial. */
    private const RENEGOTIATION_STATES = ['change_requested', 'reopen'];
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit && composer run quality`
Expected: PASS. Existing `CommentTargetMergerTest` cases must still pass unchanged.

- [ ] **Step 5: Commit**

```bash
git add src/Servicing/QuoteServicingTrigger.php src/Policy/CommentTargetMerger.php \
        tests/Unit/Servicing/QuoteServicingTriggerStatesTest.php \
        tests/Unit/Policy/CommentTargetMergerLegacyStateTest.php
git commit -m "fix(servicing): treat reopen as a renegotiation round"
```

---

### Task 7: Let the buyer gateway work without the send route

**Files:**
- Modify: `src/Bridge/SwagCommercialBuyerQuoteGateway.php`
- Modify: `src/Resources/config/services.php` (buyer gateway registration)
- Test: `tests/Unit/Bridge/SwagCommercialBuyerQuoteGatewayLegacyTest.php`

**Interfaces:**
- Consumes: `CommercialCapabilities` (Task 1).
- Produces: `SwagCommercialBuyerQuoteGateway::__construct(...)` gains `CommercialCapabilities $capabilities` as its sixth positional parameter, immediately after `$lineItemFactory` and before the seven `?object` route arguments. All seven route arguments keep their names, so the `->arg('$name', ...)` wiring is unaffected.

Three changes, all driven by the same fact: on a released SwagCommercial `QuoteRequestRoute::request()` creates the quote directly in `open`, so there is no draft to send.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Bridge/SwagCommercialBuyerQuoteGatewayLegacyTest.php`. Only `counterQuote`'s validation branch is unit-testable without a shop — `requestQuote` needs a `CartService` and a real sales-channel context, and is covered by `LegacyBuyerFlowTest` in Task 10.

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge;

use MerchantQuoteAgentPlugin\Bridge\CommercialQuoteLinePricing;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Exception\ValidationException;

/**
 * A released SwagCommercial has no `QuoteLineItemRoute`, so there is nowhere to
 * put a per-unit ask. `CommercialQuoteLinePricing::isAvailable()` already
 * reports that; what this pins is that an agent sending one gets a 422 naming
 * the reason rather than a silently discarded price.
 *
 * The gateway itself needs a shop to construct, so the boundary is asserted on
 * the collaborator that owns the decision. LegacyBuyerFlowTest drives the same
 * path end to end against the 6.7.12 shop.
 */
final class SwagCommercialBuyerQuoteGatewayLegacyTest extends TestCase
{
    public function testLinePricingReportsUnavailableWithoutTheRoute(): void
    {
        self::assertFalse((new CommercialQuoteLinePricing())->isAvailable());
    }

    public function testLinePricingReportsAvailableWithTheRoute(): void
    {
        self::assertTrue((new CommercialQuoteLinePricing(new \stdClass()))->isAvailable());
    }

    public function testACounterPriceIsRejectedWhenLinePricingIsUnavailable(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('does not support per-line price asks');

        (new CommercialQuoteLinePricing())->assertCanPriceLines();
    }

    public function testAssertingIsANoOpWhenLinePricingIsAvailable(): void
    {
        (new CommercialQuoteLinePricing(new \stdClass()))->assertCanPriceLines();

        $this->expectNotToPerformAssertions();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter SwagCommercialBuyerQuoteGatewayLegacyTest`
Expected: FAIL — `Call to undefined method CommercialQuoteLinePricing::assertCanPriceLines()`.

- [ ] **Step 3: Add the boundary check to CommercialQuoteLinePricing**

In `src/Bridge/CommercialQuoteLinePricing.php`, add beside `isAvailable()`:

```php
    /**
     * The 422 an agent gets for asking a released SwagCommercial to record a
     * per-unit ask. Named rather than silent: the price would otherwise be
     * accepted and dropped, and the agent would believe it had countered.
     *
     * @throws ValidationException
     */
    public function assertCanPriceLines(): void
    {
        if ($this->isAvailable()) {
            return;
        }

        throw new ValidationException(
            'This shop does not support per-line price asks. Send the ask as a comment instead.',
            ['$.line_items must be empty: this shop does not support per-line price asks'],
        );
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter SwagCommercialBuyerQuoteGatewayLegacyTest`
Expected: PASS, 4 tests.

- [ ] **Step 5: Change the gateway's three call sites**

In `src/Bridge/SwagCommercialBuyerQuoteGateway.php`:

**(a)** Add the import and the constructor parameter:

```php
use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
```

```php
        private readonly LineItemFactoryRegistry $lineItemFactory,
        private readonly CommercialCapabilities $capabilities,
        private readonly ?object $quoteRequestRoute = null,
```

**(b)** Replace `hasCommercialRoutes()`, dropping the send route and documenting why:

```php
    /**
     * The six routes every supported SwagCommercial has. `quoteSendRequestRoute`
     * is deliberately absent: trunk splits a quote request into create-draft
     * plus send, a released SwagCommercial creates the quote in `open` in one
     * call, so the route missing means the step does not exist — not that the
     * gateway is broken. `requestQuote()` branches on
     * `$capabilities->draftBeforeSend` instead.
     */
    private function hasCommercialRoutes(): bool
    {
        return (
            null !== $this->quoteRequestRoute
            && null !== $this->quoteLoadRoute
            && null !== $this->quoteListingRoute
            && null !== $this->quoteRequestChangeRoute
            && null !== $this->quoteDeclineRoute
            && null !== $this->quoteOrderRoute
        );
    }
```

**(c)** Remove `$this->linePricing->isAvailable()` from `isAvailable()` — per-line pricing is now an optional capability, not a precondition for the whole gateway:

```php
    #[Override]
    public function isAvailable(): bool
    {
        return (
            $this->hasCommercialRoutes()
            && $this->access->isAvailable()
            && CommercialAvailability::isLicensed()
        );
    }
```

**(d)** In `requestQuote()`, reject asks the backend cannot record, and make the send step conditional. Replace the loop's price collection:

```php
            /** @mago-expect analysis:possibly-invalid-argument */
            $requestedPrice = $this->linePricing->requestedPrice($lineItem, \sprintf(
                '$.line_items[%d].requested_unit_price',
                $index,
            ));
            if (null !== $requestedPrice) {
                $this->linePricing->assertCanPriceLines();
                $requestedPrices[$productId] = $requestedPrice;
            }
```

and replace the send call:

```php
        /** @mago-expect analysis:mixed-argument */
        $this->linePricing->applyRequestedPrices($quoteId, $quote, $requestedPrices, $context);

        if ($this->capabilities->draftBeforeSend) {
            CommercialQuoteAccess::service($this->quoteSendRequestRoute, 'quote send-request')->sendRequest(
                $context,
                $quoteId,
                new RequestDataBag(['comment' => trim($comment ?? '')]),
            );
        }

        return $this->loadSnapshot($quoteId, $context);
```

**(e)** The comment now needs a home on the legacy path, because `sendRequest()` was carrying it. A released `QuoteRequestRoute::request()` accepts a `RequestDataBag` with the same `comment` key (`QuoteCommenter::COMMENT_KEY === 'comment'` on every supported release), so pass it there. Replace the request call:

```php
        $quote = CommercialQuoteAccess::service($this->quoteRequestRoute, 'quote request')
            ->request($context, new RequestDataBag(['comment' => trim($comment ?? '')]))
            ->getQuote();
```

This is safe on trunk too: `request()` takes `?RequestDataBag $dataBag = null` on both, and on trunk the draft-stage comment is then followed by the send-stage one. To avoid posting the comment twice on trunk, pass it to `request()` **only** on the legacy path:

```php
        $requestBag = $this->capabilities->draftBeforeSend
            ? null
            : new RequestDataBag(['comment' => trim($comment ?? '')]);

        $quote = CommercialQuoteAccess::service($this->quoteRequestRoute, 'quote request')
            ->request($context, $requestBag)
            ->getQuote();
```

**(f)** In `counterQuote()`, guard before touching pricing. Find the branch that calls `$this->linePricing->applyCounterPrices(...)` and precede it with:

```php
        if ([] !== $lineItems) {
            $this->linePricing->assertCanPriceLines();
        }
```

- [ ] **Step 6: Register the new argument**

In `src/Resources/config/services.php`, add to the `SwagCommercialBuyerQuoteGateway` definition, before the route args:

```php
        ->arg('$capabilities', service(CommercialCapabilities::class))
```

- [ ] **Step 7: Run the full suite and quality gates**

Run: `vendor/bin/phpunit && composer run quality`
Expected: all green.

- [ ] **Step 8: Commit**

```bash
git add src/Bridge/SwagCommercialBuyerQuoteGateway.php src/Bridge/CommercialQuoteLinePricing.php \
        src/Resources/config/services.php \
        tests/Unit/Bridge/SwagCommercialBuyerQuoteGatewayLegacyTest.php
git commit -m "feat(bridge): serve buyers on a SwagCommercial without the send route"
```

---

### Task 8: Pin what each SwagCommercial release contains

**Files:**
- Create: `tests/Unit/ReleaseCapabilityMatrixTest.php`

**Interfaces:**
- Consumes: `CommercialCapabilities` (Task 1).
- Produces: nothing consumed by later tasks.

This is what catches drift between what the plan believes about a release and what it contains — including a backport landing in a patch, which is the scenario the whole field-probe design exists for. It reads the definition files straight out of the SwagCommercial clone and is skipped when that clone is absent, so it never breaks CI or a fresh checkout.

- [ ] **Step 1: Write the test**

This one has no red phase to stage: it asserts facts about an external repository, so its first run is its verification. Create `tests/Unit/ReleaseCapabilityMatrixTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * What each supported SwagCommercial release actually declares, read from the
 * clone rather than remembered.
 *
 * The plugin probes DAL fields at runtime precisely because releases differ in
 * ways a version number does not predict — `quote.cart_payload` landed in 6.7.9,
 * mid-line. That design is only as good as our belief about which release has
 * which field, and this is the only thing that checks that belief. When a
 * backport lands, this test fails and the fix is to update the matrix, not the
 * probe.
 *
 * Skipped without the clone: it is a developer-machine convenience, and CI has
 * no access to a licensed private repository.
 */
final class ReleaseCapabilityMatrixTest extends TestCase
{
    private const CLONE_PATH = '/Users/sebastian/projects/swagcommercial';

    private const LINE_ITEM_DEFINITION =
        'src/B2B/QuoteManagement/Entity/QuoteLineItem/QuoteLineItemDefinition.php';

    private const COMMENT_DEFINITION =
        'src/B2B/QuoteManagement/Entity/QuoteComment/QuoteCommentDefinition.php';

    /**
     * @return iterable<string, array{string, bool, bool, bool}>
     *     tag => [tag, lineItemAsks, softDeleteLines, lineScopedComments]
     */
    public static function releases(): iterable
    {
        yield 'floor 6.7.1.2' => ['v6.7.1.2', false, false, false];
        yield '6.7.5.0' => ['v6.7.5.0', false, false, false];
        yield '6.7.9.1' => ['v6.7.9.1', false, false, false];
        yield 'newest release 6.7.12.0' => ['v6.7.12.0', false, false, false];
        yield 'unreleased trunk' => ['trunk', true, true, true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('releases')]
    public function testTheDeclaredFieldsMatchOurCapabilityMatrix(
        string $tag,
        bool $lineItemAsks,
        bool $softDeleteLines,
        bool $lineScopedComments,
    ): void {
        $lineItem = self::show($tag, self::LINE_ITEM_DEFINITION);
        $comment = self::show($tag, self::COMMENT_DEFINITION);

        self::assertSame(
            $lineItemAsks,
            str_contains($lineItem, "'requested_price'"),
            $tag . ' disagrees with the matrix about quote_line_item.requested_price',
        );
        self::assertSame(
            $softDeleteLines,
            str_contains($lineItem, "'deleted_at'"),
            $tag . ' disagrees with the matrix about quote_line_item.deleted_at',
        );
        self::assertSame(
            $lineScopedComments,
            str_contains($comment, "'quote_line_item_id'"),
            $tag . ' disagrees with the matrix about quote_comment.quote_line_item_id',
        );
    }

    public function testTheSupportFloorIsWhereCommentEmployeeIdAppears(): void
    {
        self::assertStringNotContainsString(
            "'employee_id'",
            self::show('v6.7.0.1', self::COMMENT_DEFINITION),
            '6.7.0.x should lack employee_id — it is why the floor is 6.7.1.2',
        );
        self::assertStringContainsString(
            "'employee_id'",
            self::show('v6.7.1.2', self::COMMENT_DEFINITION),
            'the support floor must have employee_id; QuoteCommentMapper reads it unguarded',
        );
    }

    private static function show(string $tag, string $path): string
    {
        if (!is_dir(self::CLONE_PATH . '/.git')) {
            self::markTestSkipped('No SwagCommercial clone at ' . self::CLONE_PATH);
        }

        $command = sprintf(
            'git -C %s show %s 2>/dev/null',
            escapeshellarg(self::CLONE_PATH),
            escapeshellarg($tag . ':' . $path),
        );

        $output = shell_exec($command);

        self::assertIsString($output, 'git show failed for ' . $tag . ':' . $path);
        self::assertNotSame('', trim($output), 'empty file for ' . $tag . ':' . $path);

        return $output;
    }
}
```

- [ ] **Step 2: Run it and confirm every row passes**

Run: `vendor/bin/phpunit --filter ReleaseCapabilityMatrixTest`
Expected: PASS, 6 tests (5 data rows + the floor test). If any row fails, the matrix is wrong, not the code — fix the expectation and note the discovery in the commit message.

- [ ] **Step 3: Confirm it skips cleanly without the clone**

Run: `vendor/bin/phpunit --filter ReleaseCapabilityMatrixTest` after temporarily pointing `CLONE_PATH` at a nonexistent directory.
Expected: 6 skipped, 0 failures. Restore the constant afterwards.

- [ ] **Step 4: Commit**

```bash
git add tests/Unit/ReleaseCapabilityMatrixTest.php
git commit -m "test: pin which SwagCommercial release declares which quote field"
```

---

### Task 9: Probe the real shops

**Files:**
- Create: `tests/Integration/CapabilityProbeTest.php`
- Modify: `tests/Integration/GatewayWiringTest.php`
- Modify: `scripts/test-integration.sh` (comment only)

**Interfaces:**
- Consumes: `CommercialCapabilities`, `CommercialCapabilitiesFactory` (Task 1).
- Produces: nothing consumed by later tasks.

`scripts/test-integration.sh` already takes `SHOP_CONTAINER`, so a second shop needs no new plumbing — only a container. Stand up a shop with SwagCommercial 6.7.12 and name it `merchant-quote-shop-6712`.

- [ ] **Step 1: Stand up the 6.7.12 shop**

Bring up a second Shopware container with SwagCommercial pinned to `v6.7.12.0`, licensed for `QUOTE_MANAGEMENT-6302947`, named `merchant-quote-shop-6712`. Confirm it is reachable:

```bash
docker exec merchant-quote-shop-6712 php8.3 /var/www/html/bin/console plugin:list | grep -i commercial
```

Expected: SwagCommercial listed, active, version 6.7.12.x.

- [ ] **Step 2: Write the probe test**

Create `tests/Integration/CapabilityProbeTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilitiesFactory;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;

/**
 * The factory reads the live DAL registry, so this is the only place it can be
 * checked at all — and it is checked against both shops, because the whole
 * point of the object is that the two answer differently.
 *
 * The assertions are internally consistent rather than hardcoded per shop: the
 * suite runs against whichever container SHOP_CONTAINER names, and hardcoding
 * "this shop is legacy" would fail the moment someone upgrades it. What is
 * pinned instead is that the probe agrees with the container's own schema, and
 * that the four flags move together in the combinations that actually ship.
 */
final class CapabilityProbeTest extends IntegrationTestCase
{
    public function testTheProbeAgreesWithTheShopsOwnSchema(): void
    {
        $registry = static::getContainer()->get(DefinitionInstanceRegistry::class);
        self::assertInstanceOf(DefinitionInstanceRegistry::class, $registry);

        $capabilities = (new CommercialCapabilitiesFactory($registry))->create();

        self::assertSame(
            $registry->getByEntityName('quote_line_item')->getFields()->get('requestedPrice') !== null,
            $capabilities->lineItemAsks,
        );
        self::assertSame(
            $registry->getByEntityName('quote_line_item')->getFields()->get('deletedAt') !== null,
            $capabilities->softDeleteLines,
        );
        self::assertSame(
            $registry->getByEntityName('quote_comment')->getFields()->get('quoteLineItemId') !== null,
            $capabilities->lineScopedComments,
        );
    }

    public function testTheContainerServiceIsTheProbedOne(): void
    {
        $fromContainer = static::getContainer()->get(CommercialCapabilities::class);
        self::assertInstanceOf(CommercialCapabilities::class, $fromContainer);

        $registry = static::getContainer()->get(DefinitionInstanceRegistry::class);
        self::assertInstanceOf(DefinitionInstanceRegistry::class, $registry);

        self::assertEquals((new CommercialCapabilitiesFactory($registry))->create(), $fromContainer);
    }

    /**
     * The two combinations that ship. A shop with line-item asks but no
     * soft-delete would be a backport we have not accounted for, and the read
     * path's assumptions deserve to be re-checked before it is served.
     */
    public function testTheShopIsOneOfTheTwoKnownProfiles(): void
    {
        $capabilities = static::getContainer()->get(CommercialCapabilities::class);
        self::assertInstanceOf(CommercialCapabilities::class, $capabilities);

        self::assertContains(
            $capabilities,
            [CommercialCapabilities::modern(), CommercialCapabilities::legacy()],
            'This shop mixes capabilities in a combination no release ships. '
            . 'Re-read the bridge guards before trusting them here.',
        );
    }
}
```

- [ ] **Step 3: Make the wiring test tolerate a shop without the two routes**

In `tests/Integration/GatewayWiringTest.php`, `injectedBuyerCommercialIds()` currently requires all nine ids to resolve, including the two trunk-only routes. Split it:

```php
    /**
     * The seven buyer-side ids every supported SwagCommercial has.
     *
     * @return list<non-empty-string>
     */
    private static function injectedBuyerCommercialIds(): array
    {
        return [
            CommercialAvailability::QUOTE_REQUEST_ROUTE,
            CommercialAvailability::QUOTE_LOAD_ROUTE,
            CommercialAvailability::QUOTE_LISTING_ROUTE,
            CommercialAvailability::QUOTE_REQUEST_CHANGE_ROUTE,
            CommercialAvailability::QUOTE_DECLINE_ROUTE,
            CommercialAvailability::QUOTE_ORDER_ROUTE,
            CommercialAvailability::CUSTOMER_SPECIFIC_FEATURE_SERVICE,
        ];
    }

    /**
     * The two ids only an unreleased SwagCommercial has. Their absence is a
     * supported configuration, so this asserts the implication rather than the
     * presence: a shop that has `requested_price` must also have both routes,
     * because that is the release they shipped in.
     */
    public function testTheTrunkOnlyRoutesTrackTheLineItemAskCapability(): void
    {
        $capabilities = static::getContainer()->get(CommercialCapabilities::class);
        self::assertInstanceOf(CommercialCapabilities::class, $capabilities);

        if (!$capabilities->lineItemAsks) {
            self::assertFalse(class_exists(CommercialAvailability::QUOTE_LINE_ITEM_ROUTE));
            self::assertFalse(class_exists(CommercialAvailability::QUOTE_SEND_REQUEST_ROUTE));

            return;
        }

        static::commercialService(CommercialAvailability::QUOTE_LINE_ITEM_ROUTE);
        static::commercialService(CommercialAvailability::QUOTE_SEND_REQUEST_ROUTE);
    }
```

Add the `CommercialCapabilities` import to that file.

- [ ] **Step 4: Run both shops**

```bash
composer run test:integration
SHOP_CONTAINER=merchant-quote-shop-6712 composer run test:integration
```

Expected: both suites green. The 6.7.12 run is the first real proof the plan works — expect it to surface anything Tasks 2–7 missed.

- [ ] **Step 5: Document the second shop**

In `scripts/test-integration.sh`, extend the usage comment:

```bash
#   composer run test:integration                       # against merchant-quote-shop
#   composer run test:integration -- --filter Fetch     # phpunit args pass through
#   SHOP_CONTAINER=shopware-trunk composer run test:integration   # another shop
#
# Two shops matter for this suite. `merchant-quote-shop` runs unreleased
# SwagCommercial (trunk); `merchant-quote-shop-6712` runs the newest release,
# 6.7.12. The plugin supports 6.7.1.2 and up, and the two shops are the two
# capability profiles — a change to src/Bridge should run against both.
```

- [ ] **Step 6: Commit**

```bash
git add tests/Integration/CapabilityProbeTest.php tests/Integration/GatewayWiringTest.php \
        scripts/test-integration.sh
git commit -m "test(integration): probe capabilities on both shop profiles"
```

---

### Task 10: End-to-end on the released shop

**Files:**
- Create: `tests/Integration/LegacyBuyerFlowTest.php`

**Interfaces:**
- Consumes: everything above.
- Produces: nothing.

The last gate. `BuyerQuoteFlowTest` already drives the buyer surface against trunk; this drives the same surface against 6.7.12 and asserts the two documented differences.

- [ ] **Step 1: Write the test**

Read `tests/Integration/BuyerQuoteFlowTest.php` and `tests/Integration/BuyerQuoteFixture.php` first, and reuse their fixture helpers rather than building a customer and product afresh. Create `tests/Integration/LegacyBuyerFlowTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
use Ucp\Sdk\Exception\ValidationException;

/**
 * The buyer surface against a released SwagCommercial.
 *
 * Skipped on a modern shop rather than duplicated: BuyerQuoteFlowTest already
 * covers trunk, and the cases here are specifically about what a released
 * backend does differently — one-step quote creation, and a per-line ask that
 * must be refused rather than dropped.
 */
final class LegacyBuyerFlowTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $capabilities = static::getContainer()->get(CommercialCapabilities::class);
        self::assertInstanceOf(CommercialCapabilities::class, $capabilities);

        if ($capabilities->lineItemAsks) {
            self::markTestSkipped('This shop has line-item asks; BuyerQuoteFlowTest covers it.');
        }
    }

    public function testTheGatewayIsAvailableWithoutTheSendRoute(): void
    {
        self::assertTrue(static::buyerGateway()->isAvailable());
    }

    public function testAQuoteRequestLandsOpenInOneCallAndCarriesTheComment(): void
    {
        $context = BuyerQuoteContextFixture::customerContext(static::getContainer());
        $productId = BuyerQuoteFixture::product(static::getContainer());

        $snapshot = static::buyerGateway()->requestQuote(
            $context,
            [['product_id' => $productId, 'quantity' => 3]],
            'Please quote 3 units.',
        );

        self::assertSame('open', $snapshot->state);
        self::assertNotSame([], $snapshot->comments);
        self::assertSame('Please quote 3 units.', $snapshot->comments[0]['comment']);
    }

    public function testEveryPublishedLineReportsNoBuyerAsk(): void
    {
        $context = BuyerQuoteContextFixture::customerContext(static::getContainer());
        $productId = BuyerQuoteFixture::product(static::getContainer());

        $snapshot = static::buyerGateway()->requestQuote(
            $context,
            [['product_id' => $productId, 'quantity' => 1]],
            null,
        );

        self::assertNull($snapshot->lineItems[0]['requested_unit_price']);
    }

    public function testAPerLineAskOnARequestIsRefusedRatherThanDropped(): void
    {
        $context = BuyerQuoteContextFixture::customerContext(static::getContainer());
        $productId = BuyerQuoteFixture::product(static::getContainer());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('does not support per-line price asks');

        static::buyerGateway()->requestQuote(
            $context,
            [['product_id' => $productId, 'quantity' => 1, 'requested_unit_price' => 4.5]],
            null,
        );
    }

    public function testACounterWithLineItemsIsRefused(): void
    {
        $context = BuyerQuoteContextFixture::customerContext(static::getContainer());
        $productId = BuyerQuoteFixture::product(static::getContainer());

        $snapshot = static::buyerGateway()->requestQuote(
            $context,
            [['product_id' => $productId, 'quantity' => 2]],
            null,
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('does not support per-line price asks');

        static::buyerGateway()->counterQuote(
            $context,
            $snapshot->id,
            [['product_id' => $productId, 'requested_unit_price' => 4.5]],
            null,
        );
    }
}
```

If `BuyerQuoteContextFixture`/`BuyerQuoteFixture` expose differently-named helpers, use theirs — the names above mirror the file names in `tests/Integration/` and must be reconciled with what those classes actually declare.

- [ ] **Step 2: Run against the released shop**

Run: `SHOP_CONTAINER=merchant-quote-shop-6712 composer run test:integration -- --filter LegacyBuyerFlowTest`
Expected: PASS, 5 tests.

- [ ] **Step 3: Confirm it skips on trunk**

Run: `composer run test:integration -- --filter LegacyBuyerFlowTest`
Expected: 5 skipped.

- [ ] **Step 4: Run everything, both shops**

```bash
composer run quality
vendor/bin/phpunit
composer run test:integration
SHOP_CONTAINER=merchant-quote-shop-6712 composer run test:integration
```

Expected: all green. This is the acceptance gate for the whole plan.

- [ ] **Step 5: Widen the composer constraint note**

`composer.json` requires `shopware/core: ~6.7.0` and does not name SwagCommercial at all (ADR 0001 — it is a runtime-detected soft dependency), so **no dependency change is needed**. Confirm this explicitly rather than assuming:

```bash
grep -i commercial composer.json
```

Expected: no output.

- [ ] **Step 6: Update the README's compatibility statement**

In `README.md`, find the section stating SwagCommercial requirements and replace the version claim with:

```markdown
Requires SwagCommercial with B2B quote management licensed
(`QUOTE_MANAGEMENT-6302947`), version **6.7.1.2 or newer**. The plugin probes
what the installed SwagCommercial can do rather than checking its version: a
release without `quote_line_item.requested_price` (everything up to and
including 6.7.12) simply has no structured per-line buyer ask, and the agent
reads asks from quote comments instead. Merchant-side concessions — per-line
offer prices and the quote-level discount — work on every supported version.
```

- [ ] **Step 7: Commit**

```bash
git add tests/Integration/LegacyBuyerFlowTest.php README.md
git commit -m "test(integration): drive the buyer surface on a released SwagCommercial"
```

---

## Self-Review

**Spec coverage.** Capability detection → Task 1. Read path (four guards) → Tasks 2, 3, 4. Write path → Task 5. State names → Task 6. Buyer gateway → Task 7. Honesty at the edges → Task 7 (the `counterQuote` 422; the spec's revised §6 explicitly leaves the OpenAPI document and the extract prompt alone, so there is deliberately no task for them). Testing: unit → Tasks 1–7; integration on a second shop → Tasks 9, 10; capability-probe correctness → Task 8. Every spec section maps to a task.

**Placeholder scan.** No TBDs. Two tasks intentionally say "read the existing file first" rather than restating a fixture: Task 6's `CommentTargetMergerTest` helpers and Task 10's `BuyerQuoteFixture` helpers. Both are instructions to reuse an existing exact shape rather than invent one, and inventing a parallel fixture would be the worse outcome — but both need the executor to open the file, so they are flagged here rather than hidden.

**Type consistency.** `CommercialCapabilities` property names (`lineItemAsks`, `softDeleteLines`, `lineScopedComments`, `draftBeforeSend`) are used identically in Tasks 2–10. `CommercialCapabilities::modern()`/`legacy()` are defined in Task 1 and used as fixtures in every later unit test. `QuoteLineNet::of()`'s new third parameter (Task 2) is supplied by its only caller in Task 3. `CommercialQuoteLinePricing::assertCanPriceLines()` is defined in Task 7 Step 3 and called in Task 7 Steps 5(d) and 5(f), and asserted against in Task 10.

**One risk worth naming.** Task 7(e) posts the request comment through `QuoteRequestRoute::request()` on legacy and through `sendRequest()` on trunk. If a released SwagCommercial turns out to post the comment in both places, the legacy shop would get a duplicate — Task 10's `testAQuoteRequestLandsOpenInOneCallAndCarriesTheComment` asserts `comments[0]` but not the count. If duplicates appear when that test first runs against the 6.7.12 shop, tighten it to `assertCount(1, ...)` and drop the dataBag from whichever call is redundant.

---

**Plan complete and saved to `docs/superpowers/plans/2026-09-08-legacy-swagcommercial-compatibility.md`.** Two execution options:

**1. Subagent-Driven (recommended)** — a fresh subagent per task, review between tasks, fast iteration.

**2. Inline Execution** — execute tasks in this session using executing-plans, batch execution with checkpoints.
