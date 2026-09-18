# Merchant Handover Stand-Down Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A servicing pass stands down, without a model call and without a write, when a human merchant acted on the quote more recently than the buyer's newest input.

**Architecture:** Two new facts reach the pass — the `updatedAt` of each quote line, and the time of the newest administration-driven state transition (core's `state_machine_history.user_id` is non-null only for an `AdminApiSource`). `BuyerConversation` stops discarding merchant comments and gains a third bucket. One pure predicate, `Negotiation\MerchantHandover::tookOver()`, compares the newest human-merchant action against the newest buyer input, and `NegotiationPipeline::negotiate()` returns a new `NegotiationOutcome::HandedOver` before the extract call when it says yes.

**Tech Stack:** PHP 8.3, Shopware 6.7 DAL, PHPUnit 11, Mago (format/lint/analyze), Vue/TypeScript for the administration module with assert-based `.mjs` self-checks.

**Spec:** `docs/superpowers/specs/2026-09-16-merchant-handover-stand-down-design.md`

## Global Constraints

- `declare(strict_types=1)` in every PHP file; target PHP 8.3.
- Gate thresholds: cyclomatic complexity 10 (aggregated per class), nesting depth 4, **5 constructor/method parameters**, ~400 lines per file.
- Core support floor is **6.7.1.0**. Every Shopware class imported must exist at `v6.7.1.0` (`CoreFloorCompatibilityTest`). `StateMachineHistoryDefinition` does (`since(): '6.0.0.0'`).
- `src/Negotiation` must not import Shopware beyond `IllegalTransitionException`; `src/Policy` imports no Shopware at all (`NamespacePurityTest`). Cross-namespace plugin imports are fine — `OfferRound` already imports `Servicing\ServicingFingerprint`.
- Every read of a Shopware or SwagCommercial entity goes through `src/Bridge` (ADR 0001). Repositories are resolved by string id in `src/Resources/config/services.php`, never autowired.
- Nothing customer-facing may leak internal detail. Nothing in this plan writes to the buyer at all.
- Narrowest useful check per task: `composer run test` (unit, no kernel), `composer run format:check && composer run lint`, `composer run typecheck` (src only), `composer run quality:admin` for administration changes.

---

### Task 1: The read model carries each line's `updatedAt`

A comment-less buyer ask — a per-line `requested_price` typed in the storefront — has no timestamp in the read model today. `quote_line_item.updated_at` is the only one available, and `Entity::get('updatedAt')` is safe on every shop shape because `createdAt`/`updatedAt` are declared on core's `Entity` base class itself (verified at `v6.7.1.0`, `Entity.php:23-25`), not on the generated quote-line entity. No `CommercialCapabilities` gate is needed.

**Files:**
- Modify: `src/Bridge/Data/QuoteLineSnapshot.php`
- Modify: `src/Bridge/QuoteLineMapper.php:57-72` (the `line()` method)
- Test: `tests/Unit/Bridge/QuoteLineMapperTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `QuoteLineSnapshot::$updatedAt` — `?\DateTimeImmutable`, the line's `updatedAt` or, when that is null, its `createdAt`; null when the entity carries neither.

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Bridge/QuoteLineMapperTest.php`:

```php
    public function testALinesUpdatedAtReachesTheReadModel(): void
    {
        $line = new ModernLineItemEntity(id: 'line-1');
        $line->setUpdatedAt(new \DateTimeImmutable('2026-09-16 11:00:00'));

        $lines = (new QuoteLineMapper(CommercialCapabilities::modern()))->map(self::quoteWithLines([$line]));

        self::assertSame(
            '2026-09-16 11:00:00',
            $lines[0]->updatedAt?->format('Y-m-d H:i:s'),
            'A per-line ask arrives with no comment, so the line timestamp is the only date it has.',
        );
    }

    /** A line written once and never edited has no `updatedAt` at all; the DAL leaves it null. */
    public function testALineNeverEditedFallsBackToItsCreatedAt(): void
    {
        $line = new ModernLineItemEntity(id: 'line-1');
        $line->setCreatedAt(new \DateTimeImmutable('2026-09-16 09:00:00'));

        $lines = (new QuoteLineMapper(CommercialCapabilities::modern()))->map(self::quoteWithLines([$line]));

        self::assertSame('2026-09-16 09:00:00', $lines[0]->updatedAt?->format('Y-m-d H:i:s'));
    }
```

- [ ] **Step 2: Run the test and watch it fail**

```bash
vendor/bin/phpunit --filter 'QuoteLineMapperTest::testALinesUpdatedAt|QuoteLineMapperTest::testALineNeverEdited'
```

Expected: FAIL — `Unknown named parameter $updatedAt` is not raised yet; the failure is `assertSame` against `null`, because `QuoteLineSnapshot` has no such property. If PHP raises "Undefined property", that is the same failure.

- [ ] **Step 3: Add the property**

In `src/Bridge/Data/QuoteLineSnapshot.php`, add a seventh promoted property after `$netRatio`:

```php
        /**
         * When this line was last written, by anyone.
         *
         * The one ask that arrives without a comment is a per-line
         * `requested_price`, so this is the only date a comment-less ask has.
         * It is NOT a buyer signal on its own: our own price writes move it
         * too, which is why MerchantHandover dates only the line whose
         * requested price differs from the stamped fingerprint.
         */
        public ?\DateTimeImmutable $updatedAt = null,
```

The class already carries `@mago-expect lint:excessive-parameter-list` for the same reason, so no new suppression is needed.

- [ ] **Step 4: Read it in the mapper**

In `src/Bridge/QuoteLineMapper.php`, inside `line()`, before the `return`:

```php
        $updatedAt = $lineItem->get('updatedAt') ?? $lineItem->get('createdAt');
```

and add to the `new QuoteLineSnapshot(...)` argument list, after `netRatio`:

```php
            updatedAt: $updatedAt instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($updatedAt)
                : null,
```

This mirrors `QuoteSnapshotReader::readRevision()`, which does the same `updatedAt ?? createdAt` fallback for the quote itself.

- [ ] **Step 5: Run the tests and the gate**

```bash
composer run test && composer run format:check && composer run lint
```

Expected: PASS. The whole unit suite, because `QuoteLineSnapshot` is constructed in several fixtures — a new optional trailing parameter must not disturb any of them.

- [ ] **Step 6: Commit**

```bash
git add src/Bridge/Data/QuoteLineSnapshot.php src/Bridge/QuoteLineMapper.php tests/Unit/Bridge/QuoteLineMapperTest.php
git commit -m "feat(bridge): carry each quote line's updatedAt into the read model

A comment-less buyer ask is a per-line requested_price, and the line's
own timestamp is the only date it has."
```

---

### Task 2: The stamped fingerprint's asks are readable

`MerchantHandover` needs to know which line's requested price moved since the last pass. `ServicingFingerprint` already composes that string, and already stamps it; it cannot currently be read back out of a stamp.

**Files:**
- Modify: `src/Servicing/ServicingFingerprint.php`
- Test: `tests/Unit/Servicing/ServicingFingerprintTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `ServicingFingerprint::asksOf(QuoteSnapshot $snapshot): string` — the asks component of a live snapshot (the existing private `asks()`, made public under a name that says it reads a snapshot).
  - `ServicingFingerprint::stampedAsks(array $customFields): string` — the asks component of the marker already on the quote, or `''` when there is no marker or the marker predates the component.

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Servicing/ServicingFingerprintTest.php`:

```php
    public function testTheStampedAsksCanBeReadBackOutOfAMarker(): void
    {
        $snapshot = QuoteSnapshotFixture::withRequestedPrice(90.0);
        $stamp = ServicingFingerprint::stamp($snapshot, 'replied');

        self::assertSame(
            ServicingFingerprint::asksOf($snapshot),
            ServicingFingerprint::stampedAsks([ServicingFingerprint::MARKER_KEY => $stamp]),
        );
    }

    /**
     * Every marker written before the asks component existed ends after the
     * third field. Reading one must not invent a fourth.
     */
    public function testAMarkerWithoutAnAsksComponentReadsAsNoAsks(): void
    {
        self::assertSame('', ServicingFingerprint::stampedAsks([
            ServicingFingerprint::MARKER_KEY => 'open|1|1756371600.000000',
        ]));
    }

    public function testAQuoteThatWasNeverServicedHasNoStampedAsks(): void
    {
        self::assertSame('', ServicingFingerprint::stampedAsks([]));
    }
```

If `QuoteSnapshotFixture` has no `withRequestedPrice()` builder, add one alongside its existing builders that returns the fixture snapshot with `requestedUnitPrice: 90.0` on `line-1`.

- [ ] **Step 2: Run the test and watch it fail**

```bash
vendor/bin/phpunit --filter ServicingFingerprintTest
```

Expected: FAIL with `Call to undefined method ...::asksOf()`.

- [ ] **Step 3: Implement**

In `src/Servicing/ServicingFingerprint.php`, rename the private `asks()` to a public `asksOf()` (updating both call sites in `of()` and `stamp()`), and add:

```php
    /**
     * The asks half of a marker already on the quote.
     *
     * compose() appends the component after the third field and only when
     * there is one, so everything from the fourth field on IS the component —
     * and a marker written before it existed has no fourth field and reads as
     * no asks. Split with a limit, because the component itself contains no
     * `|` but must survive one appearing in a future component.
     *
     * @param array<string, mixed> $customFields
     */
    public static function stampedAsks(array $customFields): string
    {
        $stamped = self::stamped($customFields);

        if ($stamped === null) {
            return '';
        }

        $parts = explode('|', $stamped, 4);

        return $parts[3] ?? '';
    }
```

- [ ] **Step 4: Run the tests**

```bash
composer run test && composer run format:check && composer run lint
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Servicing/ServicingFingerprint.php tests/Unit/Servicing/ServicingFingerprintTest.php
git commit -m "feat(servicing): read the asks component back out of a stamped fingerprint

The handover rule has to date the line whose requested price moved, which
means knowing which asks the last pass already saw."
```

---

### Task 3: The bridge reads the newest administration transition

**Files:**
- Create: `src/Bridge/MerchantActionReader.php`
- Modify: `src/Bridge/Data/QuoteLifecycle.php`
- Modify: `src/Bridge/QuoteSnapshotReader.php` (constructor, `read()`, `readLifecycle()`)
- Modify: `src/Resources/config/services.php:569-573`
- Test: `tests/Integration/MerchantActionReaderTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `MerchantActionReader::lastTransitionAt(string $quoteId, Context $context): ?\DateTimeImmutable`
  - `QuoteLifecycle::$lastAdminTransitionAt` — `?\DateTimeImmutable`, fourth constructor parameter, defaulting to `null`.

- [ ] **Step 1: Write the integration test**

This class is a DAL query; a unit test over a mocked repository would assert the mock. The real check is against a booted shop.

Create `tests/Integration/MerchantActionReaderTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\MerchantActionReader;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * `user_id` on `state_machine_history` is written only for an AdminApiSource
 * (core's StateMachineRegistry::transition()), which is what makes it readable
 * as "a human merchant moved this quote". The agent's own transitions carry a
 * SystemSource and the buyer's carry a SalesChannelApiSource; both write null.
 */
final class MerchantActionReaderTest extends IntegrationTestCase
{
    public function testAnAgentDrivenTransitionIsNotReadAsAHumansAction(): void
    {
        $reader = static::getContainer()->get(MerchantActionReader::class);
        self::assertInstanceOf(MerchantActionReader::class, $reader);

        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $before = $reader->lastTransitionAt($quoteId, Context::createDefaultContext());

        self::assertNull(
            $reader->lastTransitionAt($quoteId, Context::createDefaultContext()),
            'A read must not invent an action; this quote has had no admin transition in this test.',
        );
        self::assertSame($before, $reader->lastTransitionAt($quoteId, Context::createDefaultContext()));
    }

    public function testAHistoryRowCarryingAUserIsReadAsAHumansAction(): void
    {
        $context = Context::createDefaultContext();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), $context);
        $reader = static::getContainer()->get(MerchantActionReader::class);
        self::assertInstanceOf(MerchantActionReader::class, $reader);

        $history = static::getContainer()->get('state_machine_history.repository');
        self::assertNotNull($history);

        $stateId = static::quoteStateId($context);
        $userId = static::anyAdminUserId($context);
        $history->create([[
            'id' => Uuid::randomHex(),
            'stateMachineId' => static::quoteStateMachineId($context),
            'entityName' => 'quote',
            'referencedId' => $quoteId,
            'referencedVersionId' => $context->getVersionId(),
            'fromStateId' => $stateId,
            'toStateId' => $stateId,
            'transitionActionName' => 'test_transition',
            'userId' => $userId,
        ]], $context);

        self::assertNotNull(
            $reader->lastTransitionAt($quoteId, $context),
            'A history row carrying a user id is a human merchant acting.',
        );
    }

    /** An AdminApiSource with no user id is an integration, not a person. */
    public function testAnIntegrationsTransitionIsNotAPerson(): void
    {
        self::assertNull((new AdminApiSource(null, Uuid::randomHex()))->getUserId());
    }
}
```

Add the three helpers to the same class, each one `firstId()` through a core repository. `QuoteFixture` resolves ids the same way:

```php
    private static function quoteStateMachineId(Context $context): string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('technicalName', 'quote.state'));
        $id = static::getContainer()->get('state_machine.repository')?->searchIds($criteria, $context)->firstId();
        self::assertIsString($id, 'This shop has no quote.state state machine; SwagCommercial is not installed.');

        return $id;
    }

    private static function quoteStateId(Context $context): string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('stateMachineId', self::quoteStateMachineId($context)));
        $criteria->addFilter(new EqualsFilter('technicalName', 'open'));
        $id = static::getContainer()->get('state_machine_state.repository')?->searchIds($criteria, $context)->firstId();
        self::assertIsString($id, 'The quote state machine has no `open` state.');

        return $id;
    }

    private static function anyAdminUserId(Context $context): string
    {
        $id = static::getContainer()->get('user.repository')?->searchIds(new Criteria(), $context)->firstId();
        self::assertIsString($id, 'This shop has no administration user to attribute a transition to.');

        return $id;
    }
```

Import `Criteria` and `EqualsFilter` in the test alongside the existing imports. The row this test writes is a history row only — it transitions nothing, which is the point: the reader must read the row, not re-derive it.

- [ ] **Step 2: Run it and watch it fail**

```bash
composer run test:integration -- --filter MerchantActionReaderTest
```

Expected: FAIL — the service does not exist, so `get()` returns null and the `assertInstanceOf` fails.

If the test shop is not reachable from this session, say so and continue to Step 3 — the unit gate in later tasks still covers the decision logic, and this test must be run before the branch is finished.

- [ ] **Step 3: Write the reader**

Create `src/Bridge/MerchantActionReader.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * When a human merchant last moved this quote through its state machine.
 *
 * Core writes one `state_machine_history` row per transition and fills
 * `user_id` from the context source:
 *
 *     'userId' => $context->getSource() instanceof AdminApiSource
 *         ? $context->getSource()->getUserId() : null,
 *     -- StateMachineRegistry.php:154, v6.7.1.0
 *
 * So `user_id IS NOT NULL` is exactly "an administration user did this". The
 * buyer's storefront transitions carry a SalesChannelApiSource and the agent's
 * own carry a SystemSource; both write null by construction. This is the same
 * three-way authorship split Bridge\Data\QuoteComment documents for comments,
 * and it is the author EscalationResolutionSubscriber correctly says the core
 * state-change EVENT does not carry — true of the event, not of the row core
 * writes beside it.
 *
 * An integration acting through the admin API sets `integration_id` and leaves
 * `user_id` null, so an ERP sync is not read as a person. That is intended: the
 * rule this feeds is about a human having looked.
 *
 * A core entity rather than a SwagCommercial one, but it lives here anyway:
 * ADR 0001 puts every DAL read in the bridge, and src/Negotiation — which owns
 * the decision this feeds — may not import Shopware at all.
 */
final readonly class MerchantActionReader
{
    /** @param EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $historyRepository */
    public function __construct(
        private EntityRepository $historyRepository,
    ) {}

    public function lastTransitionAt(string $quoteId, Context $context): ?\DateTimeImmutable
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('entityName', 'quote'));
        $criteria->addFilter(new EqualsFilter('referencedId', $quoteId));
        // Deliberately NOT filtered on referencedVersionId: SwagCommercial
        // edits quotes in a version lane, and a human acting there is still a
        // human acting. The floor's index is (referenced_id,
        // referenced_version_id), whose leading column still serves this.
        $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [new EqualsFilter('userId', null)]));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
        $criteria->setLimit(1);

        $row = $this->historyRepository->search($criteria, $context)->getEntities()->first();
        $createdAt = $row instanceof Entity ? $row->get('createdAt') : null;

        return $createdAt instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($createdAt) : null;
    }
}
```

- [ ] **Step 4: Carry it on the lifecycle**

In `src/Bridge/Data/QuoteLifecycle.php`, add a fourth promoted property:

```php
        /**
         * When a human merchant last moved this quote through its state
         * machine, or null if none ever did. Transport only — see
         * MerchantActionReader for what fills it and Negotiation\MerchantHandover
         * for the decision it feeds, which also weighs the merchant's comments.
         */
        public ?\DateTimeImmutable $lastAdminTransitionAt = null,
```

In `src/Bridge/QuoteSnapshotReader.php`: take `private MerchantActionReader $merchantActions` as a fourth constructor argument (promoted, keeping the class at four — under the five-parameter cap), and pass the transition time from `read()` into `readLifecycle()`:

```php
            lifecycle: $this->readLifecycle($quote, $this->merchantActions->lastTransitionAt($quoteId, $versionedContext)),
```

```php
    private function readLifecycle(Entity $quote, ?\DateTimeImmutable $lastAdminTransitionAt): QuoteLifecycle
```

and pass it through to the `QuoteLifecycle` constructor as `lastAdminTransitionAt: $lastAdminTransitionAt`.

- [ ] **Step 5: Register the service**

In `src/Resources/config/services.php`, beside the other string-id repositories:

```php
    $services->set(MerchantActionReader::class)->args([service('state_machine_history.repository')]);
```

and extend `QuoteSnapshotReader`'s args with `service(MerchantActionReader::class)` as the fourth entry. Add the `use MerchantQuoteAgentPlugin\Bridge\MerchantActionReader;` import in alphabetical position.

- [ ] **Step 6: Run the tests**

```bash
composer run test && composer run typecheck && composer run format:check && composer run lint
```

Expected: PASS. Then, if the shop is reachable:

```bash
composer run test:integration -- --filter MerchantActionReaderTest
```

- [ ] **Step 7: Commit**

```bash
git add src/Bridge/MerchantActionReader.php src/Bridge/Data/QuoteLifecycle.php src/Bridge/QuoteSnapshotReader.php src/Resources/config/services.php tests/Integration/MerchantActionReaderTest.php
git commit -m "feat(bridge): read when a human merchant last moved the quote

Core fills state_machine_history.user_id only for an AdminApiSource, so
the row core writes beside the authorless state-change event is exactly
the author that event lacks."
```

---

### Task 4: The conversation stops discarding merchant comments

**Files:**
- Modify: `src/Negotiation/BuyerConversation.php`
- Modify: `src/Negotiation/SnapshotAdapter.php:67-85` (`conversation()`)
- Test: `tests/Unit/Negotiation/SnapshotAdapterTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `BuyerConversation::__construct(array $buyer, array $agent, array $merchant = [])` — the third bucket defaults to empty so existing construction sites keep working.
  - `BuyerConversation::merchantSpokeAt(): ?string` — the newest merchant comment as a `'U.u'` string, or null.
  - `BuyerConversation::buyerSpokeAt(): ?string` — the newest buyer comment as a `'U.u'` string, or null.
  - `BuyerConversation::agentSpokeLast(): bool` — true when an agent comment exists and is newer than every buyer and merchant comment.

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Negotiation/SnapshotAdapterTest.php`:

```php
    public function testAMerchantsNoteIsKeptSeparatelyAndNotAsTheBuyersAsk(): void
    {
        $conversation = SnapshotAdapter::conversation(NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% please', '2026-09-16 09:00:00'),
            new QuoteComment('called them, handling personally', createdById: 'admin-1', createdAt: new \DateTimeImmutable('2026-09-16 10:00:00')),
        ]));

        self::assertCount(1, $conversation->buyer, "A merchant's note is not the buyer's ask.");
        self::assertCount(0, $conversation->agent, "A merchant's note is not the agent's reply.");
        self::assertSame('1758013200.000000', $conversation->merchantSpokeAt());
    }

    public function testTheMerchantBucketNeverLeaksIntoEitherPrompt(): void
    {
        $conversation = SnapshotAdapter::conversation(NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% please', '2026-09-16 09:00:00'),
            new QuoteComment('internal: margin is thin', createdById: 'admin-1', createdAt: new \DateTimeImmutable('2026-09-16 10:00:00')),
        ]));

        self::assertSame('', $conversation->agentText());
        self::assertSame('5% please', $conversation->newestBuyerText());
    }

    public function testAnAgentReplyNewerThanEveryHumanIsAStrandedReply(): void
    {
        $conversation = SnapshotAdapter::conversation(NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% please', '2026-09-16 09:00:00'),
            NegotiationFixture::agentComment('here is 5%', '2026-09-16 09:30:00'),
        ]));

        self::assertTrue($conversation->agentSpokeLast());
    }

    public function testAMerchantWritingAfterTheAgentIsNotAStrandedReply(): void
    {
        $conversation = SnapshotAdapter::conversation(NegotiationFixture::snapshot(comments: [
            NegotiationFixture::agentComment('here is 5%', '2026-09-16 09:30:00'),
            new QuoteComment('I took this one over', createdById: 'admin-1', createdAt: new \DateTimeImmutable('2026-09-16 10:00:00')),
        ]));

        self::assertFalse($conversation->agentSpokeLast());
    }

    public function testAQuoteWithNoCommentsAtAllHasNoStrandedReply(): void
    {
        self::assertFalse(SnapshotAdapter::conversation(NegotiationFixture::snapshot())->agentSpokeLast());
    }
```

Verify the `'1758013200.000000'` literal by running `date -u -j -f '%Y-%m-%d %H:%M:%S' '2026-09-16 10:00:00' +%s` before committing; if the suite's timezone makes it differ, assert against `(new \DateTimeImmutable('2026-09-16 10:00:00'))->format('U.u')` instead of a literal.

- [ ] **Step 2: Run the test and watch it fail**

```bash
vendor/bin/phpunit --filter SnapshotAdapterTest
```

Expected: FAIL with `Call to undefined method ...::merchantSpokeAt()`.

- [ ] **Step 3: Add the bucket**

In `src/Negotiation/BuyerConversation.php`, add the third promoted property and the three readers. Note `newest()` already exists as a private static returning `?string` in `'U.u'` form; the new readers are one line each over it:

```php
    /**
     * @param list<QuoteComment> $buyer
     * @param list<QuoteComment> $agent
     * @param list<QuoteComment> $merchant the administration's own notes: `createdById` and neither buyer column
     */
    public function __construct(
        public array $buyer,
        public array $agent,
        public array $merchant = [],
    ) {}
```

```php
    /** The newest merchant note, as a 'U.u' string, or null when there is none. */
    public function merchantSpokeAt(): ?string
    {
        return self::newest($this->merchant);
    }

    /** The newest buyer ask, as a 'U.u' string, or null when there is none. */
    public function buyerSpokeAt(): ?string
    {
        return self::newest($this->buyer);
    }

    /**
     * True when the agent's own reply is the newest comment on the quote.
     *
     * That shape IS a stranded reply: only the agent writes an author-less
     * comment (#3, pinned by AddCommentTest), so a pass that posted its reply
     * and then died before the transition leaves exactly this. No human can
     * produce it, which is what makes it safe for OfferRound to finish a
     * transition on the strength of it.
     */
    public function agentSpokeLast(): bool
    {
        $agent = self::newest($this->agent);

        if ($agent === null) {
            return false;
        }

        foreach ([self::newest($this->buyer), self::newest($this->merchant)] as $human) {
            if ($human !== null && $human >= $agent) {
                return false;
            }
        }

        return true;
    }
```

Update the class docblock: the merchant's note is no longer "in neither" bucket — it has its own, read by `MerchantHandover` and by nothing that composes a prompt.

- [ ] **Step 4: Fill it in the adapter**

In `src/Negotiation/SnapshotAdapter.php`, `conversation()` becomes:

```php
        $buyer = [];
        $agent = [];
        $merchant = [];

        foreach ($snapshot->content->comments as $comment) {
            if ($comment->isBuyerAuthored()) {
                $buyer[] = $comment;

                continue;
            }

            if ($comment->isAuthored()) {
                $merchant[] = $comment;

                continue;
            }

            $agent[] = $comment;
        }

        return new BuyerConversation($buyer, $agent, $merchant);
```

Keep the existing docblock's reasoning and extend it: an authored, non-buyer comment is the administration's, and it is now kept rather than dropped — still out of both prompts, but datable.

- [ ] **Step 5: Run the tests**

```bash
composer run test && composer run format:check && composer run lint
```

Expected: PASS, including `AskInterpreterTest` and `NegotiationPipelineTest` — `hasNewBuyerAsk()` is deliberately unchanged in this task, so nothing that passed before may change now.

- [ ] **Step 6: Commit**

```bash
git add src/Negotiation/BuyerConversation.php src/Negotiation/SnapshotAdapter.php tests/Unit/Negotiation/SnapshotAdapterTest.php
git commit -m "feat(negotiation): keep the merchant's comments in their own bucket

Dropped on the floor until now, which is why nothing downstream could
tell that a human had already answered."
```

---

### Task 5: The handover predicate

**Files:**
- Create: `src/Negotiation/MerchantHandover.php`
- Test: `tests/Unit/Negotiation/MerchantHandoverTest.php`

**Interfaces:**
- Consumes: `BuyerConversation::merchantSpokeAt()`, `BuyerConversation::buyerSpokeAt()` (Task 4); `QuoteLifecycle::$lastAdminTransitionAt` (Task 3); `QuoteLineSnapshot::$updatedAt` (Task 1); `ServicingFingerprint::asksOf()` / `stampedAsks()` (Task 2).
- Produces: `MerchantHandover::tookOver(QuoteSnapshot $snapshot, BuyerConversation $conversation): bool`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Negotiation/MerchantHandoverTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteComment;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteContent;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Negotiation\MerchantHandover;
use MerchantQuoteAgentPlugin\Negotiation\SnapshotAdapter;
use MerchantQuoteAgentPlugin\Servicing\ServicingFingerprint;
use PHPUnit\Framework\TestCase;

/** @see docs/superpowers/specs/2026-09-16-merchant-handover-stand-down-design.md */
final class MerchantHandoverTest extends TestCase
{
    public function testAMerchantWhoAnsweredAfterTheBuyerHasTakenOver(): void
    {
        $snapshot = self::quote(comments: [
            NegotiationFixture::buyerComment('5% please', '2026-09-16 09:00:00'),
            self::merchantComment('2026-09-16 10:00:00'),
        ]);

        self::assertTrue(MerchantHandover::tookOver($snapshot, SnapshotAdapter::conversation($snapshot)));
    }

    public function testABuyerWhoCameBackAfterTheMerchantReEnablesTheAgent(): void
    {
        $snapshot = self::quote(comments: [
            self::merchantComment('2026-09-16 10:00:00'),
            NegotiationFixture::buyerComment('can you do better?', '2026-09-16 11:00:00'),
        ]);

        self::assertFalse(MerchantHandover::tookOver($snapshot, SnapshotAdapter::conversation($snapshot)));
    }

    public function testAnAdminTransitionCountsEvenWithNoMerchantComment(): void
    {
        $snapshot = self::quote(
            comments: [NegotiationFixture::buyerComment('5% please', '2026-09-16 09:00:00')],
            lastAdminTransitionAt: new \DateTimeImmutable('2026-09-16 10:00:00'),
        );

        self::assertTrue(MerchantHandover::tookOver($snapshot, SnapshotAdapter::conversation($snapshot)));
    }

    public function testAnAdminTransitionOlderThanTheBuyersAskDoesNotStandTheAgentDown(): void
    {
        $snapshot = self::quote(
            comments: [NegotiationFixture::buyerComment('5% please', '2026-09-16 11:00:00')],
            lastAdminTransitionAt: new \DateTimeImmutable('2026-09-16 10:00:00'),
        );

        self::assertFalse(MerchantHandover::tookOver($snapshot, SnapshotAdapter::conversation($snapshot)));
    }

    public function testAQuoteNoHumanMerchantHasEverTouchedIsServiced(): void
    {
        $snapshot = self::quote(comments: [NegotiationFixture::buyerComment('5% please', '2026-09-16 09:00:00')]);

        self::assertFalse(MerchantHandover::tookOver($snapshot, SnapshotAdapter::conversation($snapshot)));
    }

    /** The comment-less ask: the buyer edits their per-line target and types nothing. */
    public function testARequestedPriceChangedAfterTheMerchantActedIsANewBuyerAsk(): void
    {
        $snapshot = self::quote(
            comments: [],
            lastAdminTransitionAt: new \DateTimeImmutable('2026-09-16 10:00:00'),
            requestedUnitPrice: 90.0,
            lineUpdatedAt: new \DateTimeImmutable('2026-09-16 11:00:00'),
            stampedAsks: '',
        );

        self::assertFalse(MerchantHandover::tookOver($snapshot, SnapshotAdapter::conversation($snapshot)));
    }

    public function testARequestedPriceChangedBeforeTheMerchantActedIsTheirsToAnswer(): void
    {
        $snapshot = self::quote(
            comments: [],
            lastAdminTransitionAt: new \DateTimeImmutable('2026-09-16 12:00:00'),
            requestedUnitPrice: 90.0,
            lineUpdatedAt: new \DateTimeImmutable('2026-09-16 11:00:00'),
            stampedAsks: '',
        );

        self::assertTrue(MerchantHandover::tookOver($snapshot, SnapshotAdapter::conversation($snapshot)));
    }

    /**
     * The line whose ask the last pass ALREADY saw is not new input, however
     * recently the line itself was written — our own price write moves
     * `updatedAt` on every line we concede on.
     */
    public function testALineWeAlreadyAnsweredIsNotReadAsAFreshAsk(): void
    {
        $snapshot = self::quote(
            comments: [],
            lastAdminTransitionAt: new \DateTimeImmutable('2026-09-16 10:00:00'),
            requestedUnitPrice: 90.0,
            lineUpdatedAt: new \DateTimeImmutable('2026-09-16 11:00:00'),
            stampedAsks: 'line-1:90.00',
        );

        self::assertTrue(MerchantHandover::tookOver($snapshot, SnapshotAdapter::conversation($snapshot)));
    }

    private static function merchantComment(string $at): QuoteComment
    {
        return new QuoteComment('handled personally', createdById: 'admin-1', createdAt: new \DateTimeImmutable($at));
    }

    /** @param list<QuoteComment> $comments */
    private static function quote(
        array $comments = [],
        ?\DateTimeImmutable $lastAdminTransitionAt = null,
        ?float $requestedUnitPrice = null,
        ?\DateTimeImmutable $lineUpdatedAt = null,
        string $stampedAsks = '',
    ): QuoteSnapshot {
        $base = NegotiationFixture::snapshot(comments: $comments);
        $customFields = $stampedAsks === ''
            ? []
            : [ServicingFingerprint::MARKER_KEY => 'open|0|0|' . $stampedAsks];

        return new QuoteSnapshot(
            identity: $base->identity,
            revision: $base->revision,
            totals: $base->totals,
            lifecycle: new QuoteLifecycle(
                stateTechnicalName: $base->lifecycle->stateTechnicalName,
                expiresAt: $base->lifecycle->expiresAt,
                customFields: $customFields,
                lastAdminTransitionAt: $lastAdminTransitionAt,
            ),
            content: new QuoteContent(
                lines: [new QuoteLineSnapshot(
                    identity: new QuoteLineIdentity('line-1', 'Widget', 'prod-1'),
                    quantity: 10,
                    unitPriceNet: 100.0,
                    totalNet: 1000.0,
                    requestedUnitPrice: $requestedUnitPrice,
                    updatedAt: $lineUpdatedAt,
                )],
                comments: $comments,
            ),
        );
    }
}
```

- [ ] **Step 2: Run the test and watch it fail**

```bash
vendor/bin/phpunit --filter MerchantHandoverTest
```

Expected: FAIL with `Class "MerchantQuoteAgentPlugin\Negotiation\MerchantHandover" not found`.

- [ ] **Step 3: Implement**

Create `src/Negotiation/MerchantHandover.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Servicing\ServicingFingerprint;

/**
 * Has a human merchant already handled this quote?
 *
 * True when the merchant acted more recently than the buyer's newest input.
 * It is not a permanent handover: the buyer's next ask is newer than the
 * merchant's action by definition, and re-enables the agent by itself.
 *
 * A merchant acting by hand does not TRIGGER a pass — QuoteServicingTrigger
 * filters their comments and their transitions both. This exists for the pass
 * already in flight: the buyer asks, the message queues, and the merchant
 * answers by hand in the seconds before a worker picks it up, or while the
 * message is being redelivered after lock contention. Without this the agent
 * answers on top of them, and because OfferApplier writes line prices it can
 * also overwrite the prices the merchant just set.
 *
 * Static, in the idiom of StructuredAsk and MirroredAsks: it is a predicate
 * over a snapshot, and NegotiationPipeline is already at the five-parameter
 * constructor cap.
 */
final class MerchantHandover
{
    private function __construct() {}

    public static function tookOver(QuoteSnapshot $snapshot, BuyerConversation $conversation): bool
    {
        $merchant = self::latest($conversation->merchantSpokeAt(), self::at($snapshot->lifecycle->lastAdminTransitionAt));

        if ($merchant === null) {
            return false;
        }

        $buyer = self::latest($conversation->buyerSpokeAt(), self::freshAskAt($snapshot));

        // No datable buyer input at all, and a merchant who acted: theirs.
        return $buyer === null || $merchant > $buyer;
    }

    /**
     * When the buyer last moved a per-line target, which is the one ask that
     * arrives without a comment.
     *
     * Only the line whose requested price differs from the asks the last pass
     * stamped. `updatedAt` moves on ANY write to the line, our own price
     * concessions included, so the newest line timestamp on its own would read
     * our last pass as a fresh buyer ask on every quote we have ever serviced.
     *
     * Known ceiling, accepted: a merchant who hand-edits the buyer's own
     * `requested_price` column moves that line's `updatedAt` themselves and
     * would be read here as the buyer. The column is the buyer's own, the
     * administration gives the merchant no reason to touch it, and the one
     * writer that does — ours — is already hidden by MirroredAsks.
     */
    private static function freshAskAt(QuoteSnapshot $snapshot): ?string
    {
        if (ServicingFingerprint::asksOf($snapshot) === ServicingFingerprint::stampedAsks(
            $snapshot->lifecycle->customFields,
        )) {
            return null;
        }

        $newest = null;

        foreach ($snapshot->content->lines as $line) {
            if ($line->requestedUnitPrice === null) {
                continue;
            }

            $newest = self::latest($newest, self::at($line->updatedAt));
        }

        return $newest;
    }

    private static function at(?\DateTimeImmutable $moment): ?string
    {
        return $moment?->format('U.u');
    }

    /**
     * Compared as fixed-width 'U.u' strings rather than floats, for
     * ServicingFingerprint::newestCreatedAt()'s reason: the columns are
     * datetime(3) and a float comparison at microsecond scale is exactly the
     * rounding this must not have.
     */
    private static function latest(?string $left, ?string $right): ?string
    {
        if ($left === null) {
            return $right;
        }

        if ($right === null) {
            return $left;
        }

        return $left > $right ? $left : $right;
    }
}
```

- [ ] **Step 4: Run the tests**

```bash
composer run test && composer run typecheck && composer run format:check && composer run lint
```

Expected: PASS. `NamespacePurityTest` must stay green — nothing here imports Shopware.

- [ ] **Step 5: Commit**

```bash
git add src/Negotiation/MerchantHandover.php tests/Unit/Negotiation/MerchantHandoverTest.php
git commit -m "feat(negotiation): a predicate for 'a human merchant already has this'

Compares the merchant's newest action against the buyer's newest input,
including the comment-less per-line ask."
```

---

### Task 6: The pipeline stands down

**Files:**
- Modify: `src/Negotiation/NegotiationOutcome.php`
- Modify: `src/Negotiation/NegotiationPipeline.php:135-175` (`negotiate()`)
- Test: `tests/Unit/Negotiation/NegotiationPipelineTest.php`
- Test: `tests/Unit/Negotiation/RecordedOutcomePathsTest.php` (only if it enumerates outcomes; check before editing)

**Interfaces:**
- Consumes: `MerchantHandover::tookOver()` (Task 5).
- Produces: `NegotiationOutcome::HandedOver` with value `'handed_over'`; `answeredTheBuyer()` false for it.

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Negotiation/NegotiationPipelineTest.php`:

```php
    public function testAMerchantWhoAnsweredFirstStopsThePassBeforeAnyModelCall(): void
    {
        $harness = PipelineHarness::with([]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% please', '2026-09-16 09:00:00'),
            new QuoteComment('called them, sending a revised offer', createdById: 'admin-1', createdAt: new \DateTimeImmutable('2026-09-16 10:00:00')),
        ]);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::HandedOver, $outcome);
        self::assertSame(0, $harness->spy->calls, 'A human has this quote; the agent must not pay for a model call.');
        self::assertSame([], $harness->gateway->comments, 'The buyer must not hear from the agent as well.');
        self::assertSame([], $harness->gateway->calls, 'Standing down writes nothing at all.');
    }

    public function testTheBuyerComingBackAfterTheMerchantIsServicedAsAlways(): void
    {
        // The same three-reply script as testAnAskInTheCounterBandIsCountered:
        // the point is that a merchant's note older than the buyer's ask
        // changes nothing about a pass that would otherwise run.
        $harness = PipelineHarness::with([
            '{"price":{"additionalDiscountPercent":15}}',
            '{"action":"offer","message":"We can do 10%, valid until 2026-09-11.","terms":{"discountPercent":10}}',
            PipelineHarness::rewordedReply(),
        ]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            new QuoteComment('sent a revised offer', createdById: 'admin-1', createdAt: new \DateTimeImmutable('2026-09-16 10:00:00')),
            NegotiationFixture::buyerComment('still too expensive', '2026-09-16 11:00:00'),
        ]);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Countered, $outcome, 'A newer buyer ask re-enables the agent; this is not a permanent handover.');
        self::assertSame(3, $harness->spy->calls);
    }

    public function testTheStandDownIsRecordedAsItsOwnOutcome(): void
    {
        $harness = PipelineHarness::with([]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% please', '2026-09-16 09:00:00'),
            new QuoteComment('mine now', createdById: 'admin-1', createdAt: new \DateTimeImmutable('2026-09-16 10:00:00')),
        ]);

        $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame('handed_over', $harness->writer->drafts[0]->outcome);
    }
```

`PipelineHarness::with([])` scripts no model replies at all, so a pass that reaches the extract call fails on an exhausted `MockHttpClient` rather than quietly passing. That is what makes `spy->calls === 0` a real assertion in the first and third tests, and why the second one — which must get past the gate — scripts a full three-reply pass instead.

- [ ] **Step 2: Run the test and watch it fail**

```bash
vendor/bin/phpunit --filter NegotiationPipelineTest
```

Expected: FAIL with `Undefined constant ...NegotiationOutcome::HandedOver`.

- [ ] **Step 3: Add the outcome**

In `src/Negotiation/NegotiationOutcome.php`:

```php
    case HandedOver = 'handed_over';
```

and extend the class docblock: `HandedOver` is a pass that found a human merchant already on the quote and wrote nothing. It does not answer the buyer, so `answeredTheBuyer()` stays false for it and neither the escalation nor the clarification marker is released — the existing `answeredTheBuyer()` body needs no change, but state that this is deliberate.

- [ ] **Step 4: Branch in the pipeline**

In `src/Negotiation/NegotiationPipeline.php`, at the very top of `negotiate()`, before the `interpret()` call:

```php
        $conversation = SnapshotAdapter::conversation($snapshot);

        // Before the extract call and before the stranded-reply branch: a
        // human merchant has already answered this quote, and a second reply
        // from the agent — or worse, its line-price writes over theirs — is
        // exactly the surprise this plugin exists to prevent. Not permanent:
        // the buyer's next ask is newer than the merchant's action and
        // re-enables the agent by itself.
        if (MerchantHandover::tookOver($snapshot, $conversation)) {
            $this->logger->info('A human merchant answered this quote more recently than the buyer asked; '
            . 'standing down.', [
                'quoteId' => $snapshot->identity->quoteId,
            ]);

            return new NegotiationPass(NegotiationOutcome::HandedOver);
        }

        $ask = $this->interpreter->interpret($settings, $snapshot, $conversation);
```

(The existing line builds the conversation inline inside the `interpret()` call; reuse the local instead of composing it twice.)

If this pushes `NegotiationPipeline` over the per-class cyclomatic threshold, do not relax the threshold: move the branch and its log line into a private `handedOver()` returning `?NegotiationPass`, which is one decision point rather than two.

- [ ] **Step 5: Run the tests**

```bash
composer run test && composer run typecheck && composer run format:check && composer run lint
```

Expected: PASS. Watch `RecordedOutcomePathsTest` and `RecordedPassTest` — if either enumerates every outcome, add `handed_over` there rather than excluding it.

- [ ] **Step 6: Commit**

```bash
git add src/Negotiation/NegotiationOutcome.php src/Negotiation/NegotiationPipeline.php tests/Unit/Negotiation
git commit -m "feat(negotiation): stand down when a human merchant already answered

Before the extract call, so a pass that has nothing to do costs nothing,
and before the stranded-reply branch, so a quote a human moved into
in_review is not transitioned out from under them."
```

---

### Task 7: A stranded reply is the agent's own comment

`finishStrandedReply()` transitions any quote it finds in `in_review` to replied. Task 6 already covers a merchant who *transitioned* the quote. This covers the merchant who opened it in the administration and left it there.

**Files:**
- Modify: `src/Negotiation/OfferRound.php:148-159`
- Modify: `src/Negotiation/NegotiationPipeline.php` (the `finishStrandedReply()` call site)
- Test: `tests/Unit/Negotiation/NegotiationPipelineTest.php`

**Interfaces:**
- Consumes: `BuyerConversation::agentSpokeLast()` (Task 4).
- Produces: `OfferRound::finishStrandedReply(QuoteGatewayInterface $gateway, QuoteSnapshot $snapshot, BuyerConversation $conversation): void` — a third parameter, keeping the method at three.

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Negotiation/NegotiationPipelineTest.php`:

```php
    public function testAQuoteAHumanLeftInReviewIsNotTransitionedByUs(): void
    {
        // A merchant opened this quote in the administration and left it in
        // in_review. Nothing here is the agent's: no agent comment, so no
        // reply of ours was ever stranded.
        $harness = PipelineHarness::with([]);
        $snapshot = NegotiationFixture::snapshot(state: 'in_review', comments: []);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::NothingToDo, $outcome);
        self::assertSame([], $harness->gateway->transitions, "A human's quote is not ours to move.");
    }
```

`testAReplyPostedByADeadPassStillReachesReplied` already covers the positive case and must stay green unchanged — its fixture has the agent's comment newest, which is exactly the shape the new guard requires.

- [ ] **Step 2: Run the test and watch it fail**

```bash
vendor/bin/phpunit --filter NegotiationPipelineTest
```

Expected: FAIL — `transitions` contains `QuoteTransition::Sent`.

- [ ] **Step 3: Guard the method**

In `src/Negotiation/OfferRound.php`:

```php
    public function finishStrandedReply(
        QuoteGatewayInterface $gateway,
        QuoteSnapshot $snapshot,
        BuyerConversation $conversation,
    ): void {
        if ($snapshot->lifecycle->stateTechnicalName !== 'in_review') {
            return;
        }

        // The agent's own comment newest IS the stranded shape: #31's worker
        // died between the reply write and the `sent` transition. Only the
        // agent writes an author-less comment (#3, pinned by AddCommentTest),
        // so no human can produce it — and a quote a merchant is holding in
        // in_review, with nothing of ours on it, is not ours to finish.
        if (!$conversation->agentSpokeLast()) {
            return;
        }

        $this->logger->info('This quote was answered but never moved to replied; finishing that transition now.', [
            'quoteId' => $snapshot->identity->quoteId,
        ]);

        $this->reply->send($gateway, $snapshot->identity->quoteId, $snapshot->lifecycle->stateTechnicalName);
    }
```

- [ ] **Step 4: Pass the conversation at the call site**

In `src/Negotiation/NegotiationPipeline::negotiate()`:

```php
            $this->round->finishStrandedReply($gateway, $snapshot, $conversation);
```

`$conversation` is the local Task 6 introduced.

- [ ] **Step 5: Run the tests**

```bash
composer run test && composer run typecheck && composer run format:check && composer run lint
```

Expected: PASS, `testAReplyPostedByADeadPassStillReachesReplied` included.

- [ ] **Step 6: Commit**

```bash
git add src/Negotiation/OfferRound.php src/Negotiation/NegotiationPipeline.php tests/Unit/Negotiation/NegotiationPipelineTest.php
git commit -m "fix(negotiation): only finish a transition on a reply that is ours

A stranded reply is the agent's own comment left newest by a pass that
died before the transition. A quote a merchant is holding in in_review
carries no such comment and is not ours to move."
```

---

### Task 8: The administration says what happened

A new outcome value that the administration does not know renders as a raw key. This repo has twice shipped an admin keyed to values PHP no longer writes; the pairing is not a follow-up.

**Files:**
- Modify: `src/Resources/app/administration/src/module/merchant-quote-agent/decision.ts` (`OUTCOME_VARIANTS`, `DISPOSITIONS`, `passNotes`)
- Modify: `src/Resources/app/administration/src/module/merchant-quote-agent/decision.check.mjs`
- Modify: `src/Resources/app/administration/src/module/merchant-quote-agent/page/merchant-quote-agent-detail/index.ts` (`isNoop`)
- Modify: `src/Resources/app/administration/src/module/merchant-quote-agent/snippet/en.json`
- Modify: `src/Resources/app/administration/src/module/merchant-quote-agent/snippet/de.json`

**Interfaces:**
- Consumes: the `handed_over` outcome value (Task 6).
- Produces: nothing other code reads.

- [ ] **Step 1: Write the failing checks**

In `decision.check.mjs`, beside the existing `nothing_to_do` assertions:

```js
assert.equal(outcomeVariant('handed_over'), 'neutral');
assert.equal(answeredTheBuyer('handed_over'), false);
assert.equal(disposition('handed_over'), 'noAction');
assert.deepEqual(passNotes(vm, { outcome: 'handed_over', attempt: 0 }).map((note) => note.key), ['handedOver']);
```

Place the `passNotes` assertion beside the existing `nothingToDo` one so the `vm` stub in scope is the same.

- [ ] **Step 2: Run the checks and watch them fail**

```bash
composer run quality:admin
```

Expected: FAIL on the first new assertion — `outcomeVariant('handed_over')` returns the fallback, not `'neutral'`.

- [ ] **Step 3: Extend the maps**

In `decision.ts`:

```ts
const OUTCOME_VARIANTS: Record<string, string> = {
    offered: 'positive',
    countered: 'positive',
    replied: 'positive',
    clarified: 'info',
    escalated: 'critical',
    nothing_to_do: 'neutral',
    handed_over: 'neutral',
};
```

```ts
const DISPOSITIONS: Record<string, string> = {
    offered: 'answered',
    countered: 'answered',
    replied: 'answered',
    clarified: 'awaitingBuyer',
    nothing_to_do: 'noAction',
    handed_over: 'noAction',
};
```

and in `passNotes`, beside the `nothing_to_do` note:

```ts
    if (round.outcome === 'handed_over') {
        notes.push(note('handedOver', 'neutral'));
    }
```

In `page/merchant-quote-agent-detail/index.ts`, a stand-down carries no ask, no band and no model, so it gets the one-line card too:

```ts
                isNoop: !answered && (round.outcome === 'nothing_to_do' || round.outcome === 'handed_over'),
```

- [ ] **Step 4: Add the snippets**

`en.json`, under `merchant-quote-agent.outcome`:

```json
            "handed_over": "Left to you"
```

and under `merchant-quote-agent.note`:

```json
        "handedOver": "Someone from your team had already answered this quote more recently than the customer wrote, so the agent left it alone. It will pick the conversation up again if the customer replies."
```

`de.json`, same two places:

```json
            "handed_over": "An Ihr Team übergeben"
```

```json
        "handedOver": "Jemand aus Ihrem Team hatte dieses Angebot zuletzt beantwortet, neuer als die Nachricht des Kunden. Der Agent hat es deshalb nicht angefasst und meldet sich erst wieder, wenn der Kunde antwortet."
```

Both files are customer-facing merchant copy: no reason values, no field names, no mention of `state_machine_history`.

- [ ] **Step 5: Run the checks**

```bash
composer run quality:admin
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Resources/app/administration
git commit -m "feat(admin): show a pass that stood down for a human colleague

A new outcome value the administration does not know renders as a raw
key, so the vocabulary moves with the enum, not after it."
```

---

### Task 9: The documentation says it too

**Files:**
- Modify: `docs/end-to-end.md`
- Modify: `docs/for-merchants.md`

**Interfaces:**
- Consumes: everything above.
- Produces: nothing code reads.

- [ ] **Step 1: Document the rule in `end-to-end.md`**

Add a subsection to section 4 (the pass), before the escalation subsection, titled **"Standing down for a human"**. State: what the rule is; that a merchant's comment and their administration transitions are both read, with `state_machine_history.user_id` named as the source and `StateMachineRegistry.php:154` as its authority; that the buyer's next ask re-enables the agent; that the comment-less per-line ask is dated by the line's `updatedAt`, restricted to the line whose ask moved since the stamped fingerprint; and that the outcome is `handed_over`, which does not answer the buyer and so releases no marker.

Add `handed_over` to the outcomes the document lists, and correct the `nothing_to_do` sentence about finishing a stranded `in_review → replied` transition (line ~240) to say it happens only when the agent's own comment is the newest on the quote.

- [ ] **Step 2: Document it in `for-merchants.md`**

One short paragraph, in that document's register — no class names, no column names: if someone on your team answers a quote by hand, the agent leaves it alone; it starts again only if the customer comes back.

- [ ] **Step 3: Verify the claims you just wrote**

Re-read the two documents against the code as merged, not against this plan. Every file and line reference must resolve.

- [ ] **Step 4: Commit**

```bash
git add docs/end-to-end.md docs/for-merchants.md
git commit -m "docs: the agent stands down when a human merchant is on the quote"
```

---

## Final verification

- [ ] `composer run quality` — the full aggregate, not the per-task narrow checks.
- [ ] `composer run test:integration -- --filter MerchantActionReaderTest` against the test shop. This is the only check that proves the `user_id` read works against a real `state_machine_history`; the unit suite cannot.
- [ ] Re-read `docs/superpowers/specs/2026-09-16-merchant-handover-stand-down-design.md` against the merged branch. Anything the implementation decided differently belongs in the spec before the branch is finished, not in a reviewer's head.
- [ ] Confirm the out-of-scope item stayed out of scope: nothing in this branch feeds a merchant's comment text into the negotiate prompt. Standing down is the whole change.
