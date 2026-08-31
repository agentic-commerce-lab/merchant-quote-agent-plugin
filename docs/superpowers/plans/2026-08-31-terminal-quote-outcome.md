# Terminal Quote Outcome Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stamp `terminalState` and `terminalAt` onto the newest decision record for a quote when that quote reaches a terminal state, and show the outcome on the admin detail page.

**Architecture:** A Symfony event subscriber on the core `state_machine.quote.state_changed` event does all the filtering (enter side, live version, five terminal states) and delegates persistence to a one-method interface. The implementation behind that interface finds the newest `merchant_quote_agent_decision` row for the quote and issues a plain DAL `update()`. No migration: both columns already exist, reserved by #19.

**Tech Stack:** PHP 8.3, Shopware 6.7 DAL (attribute entity, `EntityRepository`, `Criteria`), Symfony EventDispatcher, PHPUnit 11, Shopware administration Vue/Twig (no build tooling in this repo).

**Spec:** `docs/superpowers/specs/2026-08-31-terminal-quote-outcome-design.md`

## Global Constraints

- **The five terminal states are exactly** `accepted`, `declined`, `expired`, `cancelled`, `withdrawn`. Not three, not eleven.
- **All audit writes use `Context::createDefaultContext()`** — it is system scope, which is what `#[Protection(write: [Protection::SYSTEM_SCOPE])]` on every field of `QuoteDecisionRecord` requires. A user-scoped write is rejected.
- **No migration.** `terminal_state VARCHAR(64)` and `terminal_at DATETIME(3)` already exist in `Migration1787998662CreateQuoteAgentDecision`. Do not add or edit a migration.
- **`terminalState` is `maxLength: 64` on the entity and `VARCHAR(64)` in SQL.** Keep them in step; do not change either.
- **Never expose the audit entity to the Store API.** Every `#[Field]` carries `api: ['admin-api' => true, 'store-api' => false]`. Do not add fields; do not relax this.
- **An audit write must never fail a merchant's action.** Every call into the writer from the subscriber is wrapped in `try/catch (\Throwable)` that logs at error level and swallows.
- **Every file starts** `<?php`, blank line, `declare(strict_types=1);`, blank line, `namespace …;`. Classes are `final readonly` unless they cannot be.
- **`composer run quality` must exit 0** before every commit. It runs `mago fmt --check`, `mago lint`, `mago analyze`, a 400-line-per-file check, jscpd, a dependency check and `composer audit`.
- **mago gates, measured not guessed:** class-scoped cyclomatic complexity 10, `excessive-parameter-list` 5, `too-many-methods` 10, `too-many-properties` 10, `excessive-nesting` 4, 400 physical lines per file. Suppress with `@mago-expect lint:<rule>` only where the spec sanctions it.
- **No new Composer or npm dependency. No JavaScript toolchain enters this repository** — no `package.json`, no bundler config. Shopware's own `bin/build-administration.sh` compiles the admin sources.
- **Red-test discipline.** Every test must be seen failing for the *right reason* before the implementation is written. A test that passes on first run is a test that proves nothing — investigate instead of moving on.
- **Commits must be signed.** Never pass `--no-verify`. If signing fails because the key store is locked, stop and report `BLOCKED: commit signing locked` rather than working around it.
- **Unit tests:** `vendor/bin/phpunit` (or `composer run test`), no kernel, no database.
  **Integration tests:** `composer run test:integration -- --filter <Name>`. This syncs the checkout into the `merchant-quote-shop` container and runs PHPUnit inside it. Every integration test runs in a transaction that is rolled back.

---

## File Structure

**Create:**

| File | Responsibility |
|---|---|
| `src/Audit/TerminalOutcomeWriterInterface.php` | The one-method seam. Keeps the subscriber free of Shopware persistence. |
| `src/Audit/TerminalOutcomeWriter.php` | Finds the newest record for a quote and updates two columns. The only new class touching the DAL. |
| `src/Audit/TerminalOutcomeSubscriber.php` | All the filtering, plus the log-and-swallow guard. No persistence. |
| `tests/Unit/Audit/FakeTerminalOutcomeWriter.php` | Captures calls; can be told to throw. Mirrors `FakeDecisionWriter`. |
| `tests/Unit/Audit/TerminalOutcomeSubscriberTest.php` | The filtering rules. |
| `tests/Integration/TerminalOutcomeWriterTest.php` | The writer against the real DAL. First SQL-level proof the two columns exist. |
| `tests/Integration/TerminalOutcomeSubscriptionTest.php` | End to end: a real transition stamps a real record. Proves the subscriber is *registered*. |

The spec named one integration file with two levels; splitting it in two lets a reviewer accept the writer while rejecting the wiring, and vice versa.

**Modify:**

| File | Change |
|---|---|
| `src/Resources/config/services.php` | Register the writer, alias the interface, register the subscriber. |
| `src/Audit/QuoteDecisionRecord.php` | Docblock: the two columns are no longer "reserved and never written". |
| `src/Audit/DecisionDraft.php` | Docblock: same correction in its mirror-list paragraph. |
| `src/Audit/DecisionRecordWriter.php` | Docblock: "the only class in Audit that touches Shopware" is no longer true. |
| `tests/Unit/Audit/DraftMirrorsEntityTest.php` | Rename `RESERVED_TERMINAL_FIELDS` and correct its comment. The exclusion itself stays. |
| `…/page/merchant-quote-agent-detail/merchant-quote-agent-detail.html.twig` | One new card between "told" and "failed". |
| `…/page/merchant-quote-agent-detail/index.ts` | Two methods: `terminalLabel`, `formatDate`. |
| `…/snippet/en.json`, `…/snippet/de.json` | The card's strings and the five state labels. |

---

### Task 1: The writer and its interface

**Files:**
- Create: `src/Audit/TerminalOutcomeWriterInterface.php`
- Create: `src/Audit/TerminalOutcomeWriter.php`
- Test: `tests/Integration/TerminalOutcomeWriterTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks. The DAL service id is `merchant_quote_agent_decision.repository`; the entity is `MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord`.
- Produces: `TerminalOutcomeWriterInterface::recordTerminalOutcome(string $quoteId, string $state, \DateTimeImmutable $at): void` — Task 2's subscriber calls exactly this signature; Task 2's fake implements exactly this interface.

**Background the implementer needs:**

`createdAt` is queryable on this entity even though `QuoteDecisionRecord` never declares it: `EntityDefinition::defaultFields()` adds `CreatedAtField` and `UpdatedAtField` with `ApiAware()` to every definition, and field compilation merges them with the attribute-derived ones. `CreatedAtFieldSerializer` only defaults the value to *now* when the payload omits it — so a test **may** set `createdAt` explicitly on insert, which is how the two fixture rows below get a deterministic order.

- [ ] **Step 1: Write the failing integration test**

Create `tests/Integration/TerminalOutcomeWriterTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use MerchantQuoteAgentPlugin\Audit\TerminalOutcomeWriter;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The writer against the real DAL. `terminal_state` and `terminal_at` are the
 * only two columns in the table that #19 reserved and never wrote, so nothing
 * has ever proved they exist under those names at the SQL level. These tests
 * are that proof: a wrong column name fails here as a DAL write error, not as
 * a silently dropped payload key.
 */
final class TerminalOutcomeWriterTest extends IntegrationTestCase
{
    public function testItStampsTheNewestRecordAndLeavesOlderOnesAlone(): void
    {
        $quoteId = Uuid::randomHex();
        $older = Uuid::randomHex();
        $newer = Uuid::randomHex();

        self::records()->create([
            self::row($older, $quoteId, '2026-08-30 10:00:00.000'),
            self::row($newer, $quoteId, '2026-08-30 11:00:00.000'),
        ], Context::createDefaultContext());

        self::writer()->recordTerminalOutcome($quoteId, 'accepted', new \DateTimeImmutable('2026-08-31 09:30:00'));

        self::assertSame('accepted', self::terminalStateOf($newer), 'The newest record was not stamped.');
        self::assertNotNull(self::recordOf($newer)->terminalAt, 'terminalAt stayed null on the stamped record.');
        self::assertNull(self::terminalStateOf($older), 'An older record was stamped as well.');
    }

    public function testALaterOutcomeOverwritesAnEarlierOne(): void
    {
        // A quote can expire, have its expiration extended and then be
        // accepted, all against the same record: `reopen` is not a servicing
        // trigger, so no newer record is ever created. The last word is the
        // true one.
        $quoteId = Uuid::randomHex();
        $id = Uuid::randomHex();

        self::records()->create([self::row($id, $quoteId, '2026-08-30 10:00:00.000')], Context::createDefaultContext());

        self::writer()->recordTerminalOutcome($quoteId, 'expired', new \DateTimeImmutable('2026-08-31 09:00:00'));
        self::writer()->recordTerminalOutcome($quoteId, 'accepted', new \DateTimeImmutable('2026-08-31 10:00:00'));

        self::assertSame('accepted', self::terminalStateOf($id));
    }

    public function testAQuoteWithNoRecordIsASilentNoOp(): void
    {
        // A quote the agent never serviced. #33 is explicit that inventing a
        // record here would be wrong.
        self::writer()->recordTerminalOutcome(Uuid::randomHex(), 'declined', new \DateTimeImmutable());

        $this->addToAssertionCount(1);
    }

    public function testEachOfTheFiveTerminalStatesFitsTheColumn(): void
    {
        // terminal_state is VARCHAR(64) with maxLength: 64 on the entity. A
        // state name that did not fit would fail the write, not truncate.
        foreach (['accepted', 'declined', 'expired', 'cancelled', 'withdrawn'] as $state) {
            $quoteId = Uuid::randomHex();
            $id = Uuid::randomHex();

            self::records()->create(
                [self::row($id, $quoteId, '2026-08-30 10:00:00.000')],
                Context::createDefaultContext(),
            );
            self::writer()->recordTerminalOutcome($quoteId, $state, new \DateTimeImmutable());

            self::assertSame($state, self::terminalStateOf($id));
        }
    }

    private static function writer(): TerminalOutcomeWriter
    {
        return new TerminalOutcomeWriter(self::records());
    }

    private static function records(): EntityRepository
    {
        $repository = static::getContainer()->get('merchant_quote_agent_decision.repository');
        self::assertInstanceOf(EntityRepository::class, $repository);

        return $repository;
    }

    private static function recordOf(string $id): QuoteDecisionRecord
    {
        $record = self::records()->search(new Criteria([$id]), Context::createDefaultContext())->first();
        self::assertInstanceOf(QuoteDecisionRecord::class, $record);

        return $record;
    }

    private static function terminalStateOf(string $id): ?string
    {
        return self::recordOf($id)->terminalState;
    }

    /**
     * `createdAt` is set explicitly so the two fixture rows have a
     * deterministic order. CreatedAtFieldSerializer only defaults it to now
     * when the payload omits it.
     *
     * @return array<string, mixed>
     */
    private static function row(string $id, string $quoteId, string $createdAt): array
    {
        return [
            'id' => $id,
            'quoteId' => $quoteId,
            'outcome' => 'offered',
            'createdAt' => $createdAt,
        ];
    }
}
```

- [ ] **Step 2: Run it and watch it fail for the right reason**

Run: `composer run test:integration -- --filter TerminalOutcomeWriterTest`

Expected: **Error** — `Class "MerchantQuoteAgentPlugin\Audit\TerminalOutcomeWriter" not found`.

If instead it fails on `merchant_quote_agent_decision.repository` being unresolvable, the plugin is not active in the container — stop and report that, do not work around it.

- [ ] **Step 3: Write the interface**

Create `src/Audit/TerminalOutcomeWriterInterface.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

/**
 * The seam that keeps TerminalOutcomeSubscriber free of persistence: the
 * subscriber decides whether a transition is worth recording, this persists it.
 *
 * Separate from DecisionRecordWriterInterface on purpose. DecisionRecorder
 * needs only `write()`, and widening the shared interface would force
 * FakeDecisionWriter to implement a method the recorder tests do not care
 * about.
 */
interface TerminalOutcomeWriterInterface
{
    /**
     * Stamp the outcome onto the newest decision record for the quote. A quote
     * the agent never serviced has no record and is a silent no-op — inventing
     * one would be worse than recording nothing.
     */
    public function recordTerminalOutcome(string $quoteId, string $state, \DateTimeImmutable $at): void;
}
```

- [ ] **Step 4: Write the implementation**

Create `src/Audit/TerminalOutcomeWriter.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

/**
 * A quote accumulates one record per servicing pass, and the terminal outcome
 * belongs to the last one. `reopen` is not in QuoteServicingTrigger's trigger
 * states, so a reopened quote is never serviced again and never produces a
 * newer record — whichever record is last stays last, and a plain `update()`
 * gives "the last terminal transition wins" for free.
 *
 * `createdAt` is queryable although QuoteDecisionRecord never declares it:
 * EntityDefinition::defaultFields() adds CreatedAtField and UpdatedAtField with
 * ApiAware() to every definition. The `id` sorting is a deterministic tiebreak
 * — created_at is DATETIME(3) and a pass takes seconds, so a tie is not
 * expected, but an arbitrary-but-stable order beats an undefined one.
 */
final readonly class TerminalOutcomeWriter implements TerminalOutcomeWriterInterface
{
    public function __construct(
        private EntityRepository $records,
    ) {}

    #[\Override]
    public function recordTerminalOutcome(string $quoteId, string $state, \DateTimeImmutable $at): void
    {
        // System scope, because every field carries
        // Protection(write: [Protection::SYSTEM_SCOPE]).
        $context = Context::createDefaultContext();

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('quoteId', $quoteId));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
        $criteria->addSorting(new FieldSorting('id', FieldSorting::DESCENDING));
        $criteria->setLimit(1);

        $id = $this->records->searchIds($criteria, $context)->firstId();

        if ($id === null) {
            return;
        }

        $this->records->update([[
            'id' => $id,
            'terminalState' => $state,
            'terminalAt' => $at,
        ]], $context);
    }
}
```

- [ ] **Step 5: Run the test and watch it pass**

Run: `composer run test:integration -- --filter TerminalOutcomeWriterTest`
Expected: PASS, 4 tests.

- [ ] **Step 6: Run the quality gate**

Run: `composer run quality`
Expected: exit 0. Fix anything it reports before committing.

- [ ] **Step 7: Commit**

```bash
git add src/Audit/TerminalOutcomeWriter.php src/Audit/TerminalOutcomeWriterInterface.php tests/Integration/TerminalOutcomeWriterTest.php
git commit -m "feat: stamp the terminal quote outcome onto the newest decision record

The writer half of #33. Finds the newest merchant_quote_agent_decision row for
a quote and updates terminal_state and terminal_at, which #19 reserved and
never wrote. A quote the agent never serviced is a silent no-op.

First SQL-level proof those two columns exist under those names: everything
else in the table is covered by DraftMirrorsEntityTest, and these two were
excluded from it.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: The subscriber and its wiring

**Files:**
- Create: `src/Audit/TerminalOutcomeSubscriber.php`
- Create: `tests/Unit/Audit/FakeTerminalOutcomeWriter.php`
- Test: `tests/Unit/Audit/TerminalOutcomeSubscriberTest.php`
- Modify: `src/Resources/config/services.php`

**Interfaces:**
- Consumes: `MerchantQuoteAgentPlugin\Audit\TerminalOutcomeWriterInterface::recordTerminalOutcome(string $quoteId, string $state, \DateTimeImmutable $at): void` from Task 1.
- Produces: `TerminalOutcomeSubscriber::getSubscribedEvents(): array<string, string>` returning `['state_machine.quote.state_changed' => 'onQuoteStateChanged']`, and `onQuoteStateChanged(StateMachineStateChangeEvent $event): void`. Task 3 relies on the service being registered, not on these names.

**Background the implementer needs:**

`tests/Unit/Servicing/QuoteTriggerEventFixture.php` already builds a `StateMachineStateChangeEvent` by hand. Reuse `QuoteTriggerEventFixture::stateEvent()` and `::snapshotContext()` — do not copy them, jscpd runs in the quality gate. Its signature is:

```php
public static function stateEvent(
    string $nextState,
    string $side = StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER,
    ?Context $context = null,
    string $fromState = 'draft',
): StateMachineStateChangeEvent
```

The transition it builds always carries entity id `'q1'`, which is what the assertions below expect.

- [ ] **Step 1: Write the fake**

Create `tests/Unit/Audit/FakeTerminalOutcomeWriter.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\TerminalOutcomeWriterInterface;

/** Captures what the subscriber decided to record, and can be told to fail. */
final class FakeTerminalOutcomeWriter implements TerminalOutcomeWriterInterface
{
    /** @var list<array{quoteId: string, state: string, at: \DateTimeImmutable}> */
    public array $calls = [];

    public ?\Throwable $throws = null;

    #[\Override]
    public function recordTerminalOutcome(string $quoteId, string $state, \DateTimeImmutable $at): void
    {
        $this->calls[] = ['quoteId' => $quoteId, 'state' => $state, 'at' => $at];

        if ($this->throws !== null) {
            throw $this->throws;
        }
    }
}
```

- [ ] **Step 2: Write the failing unit test**

Create `tests/Unit/Audit/TerminalOutcomeSubscriberTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\TerminalOutcomeSubscriber;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\QuoteTriggerEventFixture;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;

final class TerminalOutcomeSubscriberTest extends TestCase
{
    public function testItSubscribesToTheCoreStateChangeEvent(): void
    {
        self::assertSame(
            ['state_machine.quote.state_changed' => 'onQuoteStateChanged'],
            TerminalOutcomeSubscriber::getSubscribedEvents(),
        );
    }

    public function testEachTerminalStateIsRecorded(): void
    {
        foreach (['accepted', 'declined', 'expired', 'cancelled', 'withdrawn'] as $state) {
            $writer = new FakeTerminalOutcomeWriter();
            self::subscriber($writer)->onQuoteStateChanged(QuoteTriggerEventFixture::stateEvent($state));

            self::assertCount(1, $writer->calls, sprintf('Entering "%s" recorded nothing.', $state));
            self::assertSame('q1', $writer->calls[0]['quoteId']);
            self::assertSame($state, $writer->calls[0]['state']);
        }
    }

    /**
     * Only `accepted` is graph-terminal; the other four have a `reopen` or
     * `admin_resend` edge out. "Terminal" here is a business label, so the
     * non-terminal states have to be excluded by name.
     */
    public function testANonTerminalStateRecordsNothing(): void
    {
        $writer = new FakeTerminalOutcomeWriter();
        $subscriber = self::subscriber($writer);

        foreach (['draft', 'open', 'in_review', 'replied', 'change_requested', 'reopen'] as $state) {
            $subscriber->onQuoteStateChanged(QuoteTriggerEventFixture::stateEvent($state));
        }

        self::assertSame([], $writer->calls);
    }

    /** The event fires twice per transition, leave then enter. Only entering is news. */
    public function testTheLeaveSideRecordsNothing(): void
    {
        $writer = new FakeTerminalOutcomeWriter();

        self::subscriber($writer)->onQuoteStateChanged(QuoteTriggerEventFixture::stateEvent(
            'accepted',
            StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_LEAVE,
        ));

        self::assertSame([], $writer->calls);
    }

    /** A snapshot-lane transition is not a merchant's decision about a quote. */
    public function testATransitionOnTheSnapshotVersionRecordsNothing(): void
    {
        $writer = new FakeTerminalOutcomeWriter();

        self::subscriber($writer)->onQuoteStateChanged(QuoteTriggerEventFixture::stateEvent(
            'accepted',
            StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER,
            QuoteTriggerEventFixture::snapshotContext(),
        ));

        self::assertSame([], $writer->calls);
    }

    /**
     * A merchant clicking accept must never see a 500 because an audit write
     * failed, and SwagCommercial's expiry task must not abort its batch.
     */
    public function testAFailingWriterDoesNotBreakTheTransition(): void
    {
        $writer = new FakeTerminalOutcomeWriter();
        $writer->throws = new \RuntimeException('the database went away');

        self::subscriber($writer)->onQuoteStateChanged(QuoteTriggerEventFixture::stateEvent('accepted'));

        self::assertCount(1, $writer->calls, 'The writer was never reached.');
    }

    private static function subscriber(FakeTerminalOutcomeWriter $writer): TerminalOutcomeSubscriber
    {
        return new TerminalOutcomeSubscriber($writer, new NullLogger());
    }
}
```

- [ ] **Step 3: Run it and watch it fail for the right reason**

Run: `vendor/bin/phpunit --filter TerminalOutcomeSubscriberTest`
Expected: **Error** — `Class "MerchantQuoteAgentPlugin\Audit\TerminalOutcomeSubscriber" not found`.

- [ ] **Step 4: Write the subscriber**

Create `src/Audit/TerminalOutcomeSubscriber.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use Psr\Log\LoggerInterface;
use Shopware\Core\Defaults;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * The decision record says what the agent did; this says whether it worked.
 * #19 reserved `terminal_state` and `terminal_at` for exactly this and left
 * them unwritten so the pipeline stayed the single owner of a record's
 * insertion.
 *
 * Subscribes to the CORE event, like QuoteServicingTrigger: SwagCommercial
 * dispatches its own `state_enter.quote.state.*` events carrying a QuoteEntity,
 * but those classes are `@internal` there and ADR 0001 confines untyped
 * commercial access to the bridge's adapters.
 *
 * SwagCommercial's own expiry needs no special handling: UpdateQuoteExpireTaskHandler
 * drives ACTION_EXPIRE through StateMachineRegistry, so it fires this event
 * like any other transition.
 *
 * NO AgentContext::STATE guard, unlike QuoteServicingTrigger. The agent drives
 * exactly two transitions — `process` in OfferApplier and `sent` in
 * ReplyComposer — and neither is terminal, so this subscriber cannot hear its
 * own writes. A guard that can never fire would be worse than this comment.
 *
 * Known gap, accepted: `admin_cancel` is reachable from `in_review`, which is
 * where the agent's own pass sits. Cancel a quote mid-pass and this stamps the
 * record from the PREVIOUS pass, then NegotiationPipeline's `finally` inserts a
 * newer record carrying no outcome. It needs a merchant cancelling inside the
 * few seconds a pass runs and costs one unlabelled row. Closing it means
 * coordinating with the pipeline's record lifecycle, which recreates the
 * two-owners problem #33 was split out of #19 to avoid.
 */
final readonly class TerminalOutcomeSubscriber implements EventSubscriberInterface
{
    /**
     * The five states that end a negotiation. Only `accepted` is graph-terminal
     * — the other four have a `reopen` or `admin_resend` edge out — so this is
     * a business label, not a property the state machine guarantees.
     */
    private const TERMINAL_STATES = ['accepted', 'declined', 'expired', 'cancelled', 'withdrawn'];

    public function __construct(
        private TerminalOutcomeWriterInterface $writer,
        private LoggerInterface $logger,
    ) {}

    /** @return array<string, string> */
    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return ['state_machine.quote.state_changed' => 'onQuoteStateChanged'];
    }

    public function onQuoteStateChanged(StateMachineStateChangeEvent $event): void
    {
        // Fires twice per transition, leave then enter. Only entering is news.
        if ($event->getTransitionSide() !== StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER) {
            return;
        }

        // A snapshot-lane transition is a mirror, never a merchant's decision.
        if ($event->getContext()->getVersionId() !== Defaults::LIVE_VERSION) {
            return;
        }

        if (!\in_array($event->getStateName(), self::TERMINAL_STATES, strict: true)) {
            return;
        }

        $quoteId = $event->getTransition()->getEntityId();

        try {
            $this->writer->recordTerminalOutcome($quoteId, $event->getStateName(), new \DateTimeImmutable());
        } catch (\Throwable $e) {
            // A merchant clicking accept must never see a 500 because an audit
            // write failed, and the expiry task must not abort its batch.
            $this->logger->error('The terminal quote outcome could not be recorded.', [
                'quoteId' => $quoteId,
                'terminalState' => $event->getStateName(),
                'exception' => $e,
            ]);
        }
    }
}
```

- [ ] **Step 5: Run the unit test and watch it pass**

Run: `vendor/bin/phpunit --filter TerminalOutcomeSubscriberTest`
Expected: PASS, 6 tests.

- [ ] **Step 6: Wire the services**

In `src/Resources/config/services.php`, add these three lines directly after the existing `$services->set(DecisionRecorder::class);` line (around line 82), before the ADR 0001 comment block that follows:

```php
    // The outcome half of the audit trail (#33): a subscriber on the core
    // quote state machine stamps terminal_state / terminal_at onto the newest
    // record. autoconfigure() gives the subscriber its kernel.event_subscriber
    // tag, so only the repository argument needs naming.
    $services->set(TerminalOutcomeWriter::class)->args([service('merchant_quote_agent_decision.repository')]);
    $services->alias(TerminalOutcomeWriterInterface::class, TerminalOutcomeWriter::class);
    $services->set(TerminalOutcomeSubscriber::class);
```

Add the three matching `use` statements to the file's import block, keeping it alphabetically sorted among the existing `MerchantQuoteAgentPlugin\Audit\…` imports:

```php
use MerchantQuoteAgentPlugin\Audit\TerminalOutcomeSubscriber;
use MerchantQuoteAgentPlugin\Audit\TerminalOutcomeWriter;
use MerchantQuoteAgentPlugin\Audit\TerminalOutcomeWriterInterface;
```

- [ ] **Step 7: Run the whole unit suite and the quality gate**

Run: `vendor/bin/phpunit`
Expected: PASS, no regressions.

Run: `composer run quality`
Expected: exit 0.

- [ ] **Step 8: Commit**

```bash
git add src/Audit/TerminalOutcomeSubscriber.php src/Resources/config/services.php tests/Unit/Audit/FakeTerminalOutcomeWriter.php tests/Unit/Audit/TerminalOutcomeSubscriberTest.php
git commit -m "feat: subscribe to terminal quote transitions

The listening half of #33. Filters the core state-change event down to the
enter side, the live version and the five states that end a negotiation, then
hands off to the writer.

No AgentContext guard: the agent drives only process and sent, neither of
which is terminal, so it cannot hear its own writes. The writer call is
wrapped in log-and-swallow because a merchant clicking accept must never see a
500 from an audit write.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Prove the subscriber is actually registered

**Files:**
- Test: `tests/Integration/TerminalOutcomeSubscriptionTest.php`

**Interfaces:**
- Consumes: the services registered in Task 2 and the writer from Task 1. Nothing new is produced.

**Background the implementer needs:**

This is the test that catches the class of defect #39 exists about: a feature whose unit tests all pass and which does nothing at all because it was never wired. It must therefore **not** hand-register the subscriber. `ServicingTriggerTest::withTrigger()` deliberately does hand-register — do not copy that pattern here; it would defeat the point of this test.

The plugin is installed and active in the `merchant-quote-shop` container, so `src/Resources/config/services.php` is loaded by the integration kernel and the subscriber is on the real dispatcher.

Use `admin_cancel` from `open`, not `accept` from `replied`: `QuoteFixture::quoteIdInState()` can find an `open` quote with a line item in the seeded shop, and `admin_cancel` reaches `cancelled`, which is one of the five terminal states. `accept` would need a `replied` quote, which `ServicingTriggerTest`'s docblock records the seed as lacking.

Each integration test runs inside a transaction that is rolled back, so cancelling a real quote leaves the shop unchanged.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/TerminalOutcomeSubscriptionTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;

/**
 * The subscriber end to end, against the real dispatcher and a real quote.
 *
 * Deliberately does NOT hand-register the subscriber the way
 * ServicingTriggerTest::withTrigger() does. The whole point of this test is
 * that services.php wires it: #39 records a feature that passed every unit
 * test, compiled cleanly and did nothing, because nothing executed it.
 *
 * `admin_cancel` from `open` rather than `accept` from `replied`: the seeded
 * shop has open quotes with line items and no suitable replied one, and
 * `cancelled` is one of the five terminal states either way.
 */
final class TerminalOutcomeSubscriptionTest extends IntegrationTestCase
{
    public function testARealTerminalTransitionStampsTheRecord(): void
    {
        $context = Context::createDefaultContext();
        $quoteId = QuoteFixture::quoteIdInState(static::getContainer(), $context, 'open');
        $recordId = Uuid::randomHex();

        self::records()->create([[
            'id' => $recordId,
            'quoteId' => $quoteId,
            'outcome' => 'offered',
        ]], $context);

        $registry = static::getContainer()->get(StateMachineRegistry::class);
        self::assertInstanceOf(StateMachineRegistry::class, $registry);

        $registry->transition(new Transition('quote', $quoteId, 'admin_cancel', 'stateId'), $context);

        $record = self::records()->search(new Criteria([$recordId]), $context)->first();
        self::assertInstanceOf(QuoteDecisionRecord::class, $record);
        self::assertSame(
            'cancelled',
            $record->terminalState,
            'A real terminal transition stamped nothing: TerminalOutcomeSubscriber is not registered, '
            . 'or the core event name does not match.',
        );
        self::assertNotNull($record->terminalAt);
    }

    public function testATerminalTransitionOnAnUnservicedQuoteWritesNothing(): void
    {
        // The subscriber fires, finds no record, and must neither throw nor
        // invent a row. The transition itself has to succeed.
        $context = Context::createDefaultContext();
        $quoteId = QuoteFixture::quoteIdInState(static::getContainer(), $context, 'open');

        $registry = static::getContainer()->get(StateMachineRegistry::class);
        self::assertInstanceOf(StateMachineRegistry::class, $registry);

        $registry->transition(new Transition('quote', $quoteId, 'admin_cancel', 'stateId'), $context);

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('quoteId', $quoteId));

        self::assertSame(0, self::records()->searchIds($criteria, $context)->getTotal());
    }

    private static function records(): EntityRepository
    {
        $repository = static::getContainer()->get('merchant_quote_agent_decision.repository');
        self::assertInstanceOf(EntityRepository::class, $repository);

        return $repository;
    }
}
```

- [ ] **Step 2: Run it and confirm it passes for the right reason**

Run: `composer run test:integration -- --filter TerminalOutcomeSubscriptionTest`
Expected: PASS, 2 tests.

This test is written after the implementation, so it should pass immediately. **Prove it is not passing vacuously** before moving on: temporarily comment out the `$services->set(TerminalOutcomeSubscriber::class);` line in `src/Resources/config/services.php`, re-run, and confirm `testARealTerminalTransitionStampsTheRecord` now **fails** with the "is not registered" message. Then restore the line and re-run to green. Do not commit with the line removed.

- [ ] **Step 3: Run the quality gate**

Run: `composer run quality`
Expected: exit 0.

- [ ] **Step 4: Commit**

```bash
git add tests/Integration/TerminalOutcomeSubscriptionTest.php
git commit -m "test: prove the terminal-outcome subscriber is actually wired

A real admin_cancel on a real quote, through the real dispatcher, with no
hand-registered subscriber. #39 records a feature that passed every unit test
and did nothing because nothing executed it; this is the test that would have
caught it.

Verified non-vacuous by removing the service registration and watching it fail.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: Correct the docblocks the write path invalidates

**Files:**
- Modify: `src/Audit/QuoteDecisionRecord.php`
- Modify: `src/Audit/DecisionDraft.php`
- Modify: `src/Audit/DecisionRecordWriter.php`
- Modify: `tests/Unit/Audit/DraftMirrorsEntityTest.php`

**Interfaces:**
- Consumes: the class names `TerminalOutcomeSubscriber` and `TerminalOutcomeWriter` from Tasks 1-2. No code behaviour changes in this task.
- Produces: nothing.

Four places currently assert the opposite of what is now true. This is comment-only work plus one constant rename; no behaviour changes and no test should change its verdict.

- [ ] **Step 1: Correct `QuoteDecisionRecord`**

In `src/Audit/QuoteDecisionRecord.php`, replace this paragraph of the class docblock:

```
 * `terminalState` and `terminalAt` are reserved and never written here. The
 * subscriber that fills them is a follow-up; the columns exist so that
 * follow-up needs no migration.
```

with:

```
 * `terminalState` and `terminalAt` are the only two columns not written by a
 * servicing pass. TerminalOutcomeSubscriber stamps them later, when the quote
 * reaches one of the five states that end a negotiation, so a record's insert
 * still has exactly one owner and the outcome is a separate update.
```

- [ ] **Step 2: Correct `DecisionDraft`**

In `src/Audit/DecisionDraft.php`, replace:

```
 * Mirrors QuoteDecisionRecord's columns one-for-one, minus `id` (the writer
 * generates it), minus `terminalState`/`terminalAt` (reserved, never written
 * by this issue), plus `startedAt` (a stopwatch the writer excludes from the
 * payload).
```

with:

```
 * Mirrors QuoteDecisionRecord's columns one-for-one, minus `id` (the writer
 * generates it), minus `terminalState`/`terminalAt` (written later by
 * TerminalOutcomeSubscriber, never by a pass), plus `startedAt` (a stopwatch
 * the writer excludes from the payload).
```

- [ ] **Step 3: Correct `DecisionRecordWriter`**

In `src/Audit/DecisionRecordWriter.php`, replace the first sentence of the class docblock:

```
 * The only class in Audit that touches Shopware. Everything upstream of it —
 * the recorder, the draft, the stages — stays plain PHP and unit-testable
 * without a kernel.
```

with:

```
 * Persists one pass. Everything upstream of it — the recorder, the draft, the
 * stages — stays plain PHP and unit-testable without a kernel; that seam, not
 * exclusivity, is the invariant. TerminalOutcomeWriter is the namespace's other
 * DAL-facing class, and it only ever updates rows this one inserted.
```

- [ ] **Step 4: Rename the test constant**

In `tests/Unit/Audit/DraftMirrorsEntityTest.php`, replace:

```php
    /** Reserved for the terminal-state follow-up subscriber, not this issue. */
    private const RESERVED_TERMINAL_FIELDS = ['terminalState', 'terminalAt'];
```

with:

```php
    /**
     * Written by TerminalOutcomeSubscriber after the quote reaches a terminal
     * state, never by a pass. The exclusion stays: a draft is one pass's
     * insert, and the outcome is a later update by a different owner.
     */
    private const WRITTEN_BY_THE_TERMINAL_SUBSCRIBER = ['terminalState', 'terminalAt'];
```

and update its single use on the line that reads:

```php
        $excluded = [self::ID_IS_WRITER_GENERATED, ...self::RESERVED_TERMINAL_FIELDS];
```

to:

```php
        $excluded = [self::ID_IS_WRITER_GENERATED, ...self::WRITTEN_BY_THE_TERMINAL_SUBSCRIBER];
```

- [ ] **Step 5: Confirm nothing changed behaviourally**

Run: `vendor/bin/phpunit`
Expected: PASS, the same test count as before this task.

Run: `grep -rn "RESERVED_TERMINAL_FIELDS" src tests`
Expected: no output.

Run: `composer run quality`
Expected: exit 0.

- [ ] **Step 6: Commit**

```bash
git add src/Audit/QuoteDecisionRecord.php src/Audit/DecisionDraft.php src/Audit/DecisionRecordWriter.php tests/Unit/Audit/DraftMirrorsEntityTest.php
git commit -m "docs: correct the four places that call the terminal columns reserved

They are written now. DecisionRecordWriter is also no longer the only class in
Audit that touches Shopware; the invariant it was really protecting is that the
recorder and the draft stay plain PHP, so say that instead.

DraftMirrorsEntityTest keeps the exclusion — a draft is one pass's insert and
the outcome is a later update by a different owner — and only its name and
comment change.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Show the outcome on the admin detail page

**Files:**
- Modify: `src/Resources/app/administration/src/module/merchant-quote-agent/page/merchant-quote-agent-detail/merchant-quote-agent-detail.html.twig`
- Modify: `src/Resources/app/administration/src/module/merchant-quote-agent/page/merchant-quote-agent-detail/index.ts`
- Modify: `src/Resources/app/administration/src/module/merchant-quote-agent/snippet/en.json`
- Modify: `src/Resources/app/administration/src/module/merchant-quote-agent/snippet/de.json`

**Interfaces:**
- Consumes: the `terminalState` (string|null) and `terminalAt` (ISO 8601 string|null) properties on a `merchant_quote_agent_decision` record, both already `api: ['admin-api' => true]`. The page already loads the whole record via `decisionRepository.get()`; no new request.
- Produces: nothing consumed by other tasks.

**Background the implementer needs:**

#39 records that **nothing in this repository type-checks or lints the administration TypeScript**, and that a `repository.aggregate()` call which does not exist reached review because of it. Two rules follow. Add no new API call — this task reads two properties off a record the page already has. And verify anything you *do* call against the shop's own source before writing it.

Two things were verified that way already, so take them as given:

- `Shopware.Filter.getByName('date')` exists, at `vendor/shopware/administration/Resources/app/administration/src/app/filter/date.filter.ts`. It is `(value: string, options?: Intl.DateTimeFormatOptions) => string` and returns `''` for a falsy value.
- `this.$tc(...)` works inside a component method; the module's own list page already uses it at `merchant-quote-agent-list/index.ts:56`.

The list page is deliberately left alone. A sortable Outcome column means touching the column config and the criteria, which is exactly where #39's risk lives.

- [ ] **Step 1: Add the two methods**

In the detail page's script — the file at
`src/Resources/app/administration/src/module/merchant-quote-agent/page/merchant-quote-agent-detail/index.ts` — add these two methods to the `methods` object, directly after the existing `dash(value)` method and before the closing `},`:

```js
        /**
         * The five terminal quote states in the merchant's language. $tc
         * returns the key itself when no snippet matches, which would render as
         * a dotted path — so a state Shopware adds later shows its technical
         * name instead, which is wrong-looking but readable.
         */
        terminalLabel(state) {
            const key = `merchant-quote-agent.detail.terminal.${state}`;
            const label = this.$tc(key);

            return label === key ? state : label;
        },

        /** A timestamp a merchant can read, not an ISO string. */
        formatDate(value) {
            const dateFilter = Shopware.Filter.getByName('date');

            return dateFilter ? dateFilter(value) : String(value);
        },
```

- [ ] **Step 2: Add the card to the template**

In `merchant-quote-agent-detail.html.twig`, insert this block between the closing `{% endblock %}` of `merchant_quote_agent_detail_told` and the opening `{% block merchant_quote_agent_detail_failed %}`:

```twig
            {% block merchant_quote_agent_detail_outcome %}
            <sw-card :title="$tc('merchant-quote-agent.detail.outcome')">
                <p v-if="!record.terminalState">{{ $tc('merchant-quote-agent.detail.stillOpen') }}</p>
                <sw-description-list v-else>
                    <dt>{{ $tc('merchant-quote-agent.detail.terminalState') }}</dt>
                    <dd>{{ terminalLabel(record.terminalState) }}</dd>
                    <dt>{{ $tc('merchant-quote-agent.detail.terminalAt') }}</dt>
                    <dd>{{ formatDate(record.terminalAt) }}</dd>
                </sw-description-list>
            </sw-card>
            {% endblock %}
```

The `v-if` on `terminalState` is what makes a quote still in flight render one sentence rather than two em dashes: a missing outcome and an outcome of "nothing happened" are different facts.

- [ ] **Step 3: Add the English snippets**

In `snippet/en.json`, inside the `"detail"` object, add these entries after `"noError"` (add a comma to the `"noError"` line):

```json
            "outcome": "What happened to the quote",
            "stillOpen": "Still in flight — no final outcome yet.",
            "terminalState": "Outcome",
            "terminalAt": "When",
            "terminal": {
                "accepted": "Accepted",
                "declined": "Declined",
                "expired": "Expired",
                "cancelled": "Cancelled",
                "withdrawn": "Withdrawn"
            }
```

- [ ] **Step 4: Add the German snippets**

In `snippet/de.json`, inside the `"detail"` object, add these entries after `"noError"` (add a comma to the `"noError"` line):

```json
            "outcome": "Was aus dem Angebot geworden ist",
            "stillOpen": "Noch offen – bisher kein endgültiges Ergebnis.",
            "terminalState": "Ergebnis",
            "terminalAt": "Wann",
            "terminal": {
                "accepted": "Angenommen",
                "declined": "Abgelehnt",
                "expired": "Abgelaufen",
                "cancelled": "Storniert",
                "withdrawn": "Zurückgezogen"
            }
```

- [ ] **Step 5: Verify both snippet files still parse**

Run:

```bash
python3 -m json.tool src/Resources/app/administration/src/module/merchant-quote-agent/snippet/en.json > /dev/null && \
python3 -m json.tool src/Resources/app/administration/src/module/merchant-quote-agent/snippet/de.json > /dev/null && \
echo "both parse"
```

Expected: `both parse`. A trailing-comma mistake here breaks the whole administration build, and nothing in this repository's gates would tell you.

- [ ] **Step 6: Verify the two snippet files have the same key set**

Run:

```bash
python3 - <<'PY'
import json
base = 'src/Resources/app/administration/src/module/merchant-quote-agent/snippet/'
def keys(o, p=''):
    if isinstance(o, dict):
        return {k for key, v in o.items() for k in keys(v, f'{p}.{key}')}
    return {p}
en = keys(json.load(open(base + 'en.json')))
de = keys(json.load(open(base + 'de.json')))
print('only in en:', sorted(en - de))
print('only in de:', sorted(de - en))
PY
```

Expected: both lists empty.

- [ ] **Step 7: Build the administration in the shop and confirm the page renders**

Run:

```bash
scripts/sync-to-shop.sh
docker exec merchant-quote-shop bash -lc 'cd /var/www/html && ./bin/build-administration.sh'
```

Expected: the build completes without error.

Then open the module in the admin (Orders → Quote Agent), click into any decision, and confirm the new card appears — showing "Still in flight — no final outcome yet." for a record with no outcome. **This is a manual step and it is not optional:** #39 exists because a page that had never been opened shipped a total feature failure.

- [ ] **Step 8: Run the quality gate**

Run: `composer run quality`
Expected: exit 0. (`mago`'s `[source] paths` is `["src"]` and it reads PHP only, so this does not inspect the TypeScript — which is the gap #39 tracks, not something to fix here.)

- [ ] **Step 9: Commit**

```bash
git add src/Resources/app/administration/src/module/merchant-quote-agent/page/merchant-quote-agent-detail src/Resources/app/administration/src/module/merchant-quote-agent/snippet
git commit -m "feat: show the quote's outcome on the decision trail

One card between what the buyer was told and what went wrong, which is the
narrative order. A record with no outcome yet says so in a sentence rather
than showing two em dashes: a missing outcome and an outcome of nothing
happened are different facts.

No new API call — both properties come off the record the page already loads.
The list page is untouched: a sortable column means touching the criteria,
which is where the unchecked-TypeScript risk in #39 lives.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Done when

Every box above is checked and:

- `vendor/bin/phpunit` is green.
- `composer run test:integration` is green.
- `composer run quality` exits 0.
- The detail page has been opened in a browser and the new card was seen.
