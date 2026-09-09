# Quote Agent Dashboard Success Measures Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the quote agent dashboard's activity figures with four success measures (auto-execution rate, escalation resolution time, price retention, deal cycle time), default the quote listing to *needs review*, and fix the disposition table so that queue drains.

**Architecture:** The measures are computed client-side in the Shopware administration module, extending the existing "one read, fold, then derive" pattern in `merchant-quote-agent-list` so the tiles and the grid rows can never disagree. The only new backend is a `resolved_at`/`resolved_state` column pair on `merchant_quote_agent_decision` plus a state-machine subscriber that stamps them, because nothing available on SwagCommercial 7.12 can supply an escalation resolution time. Version-dependent quote fields are read optionally with `??` fallbacks rather than gated on `CommercialCapabilities`.

**Tech Stack:** PHP 8.3+, Shopware 6.7 core (DAL, state machine, system config), Vue 3 + Meteor components + Twig-style admin templates, plain `node --test`-free assert scripts (`*.check.mjs`) for the JS, PHPUnit for PHP.

**Spec:** `docs/superpowers/specs/2026-09-09-quote-agent-dashboard-measures-design.md`

## Global Constraints

- **Both SwagCommercial lanes must work.** Fields absent on 7.12.0 — `quote.requestedAt`, `quote.quoteCreatedAt`, `quote.totalLineItemDiscount`, and the whole `quote_history` table — may only ever be **read**, never filtered or sorted on, and every read needs a `??` fallback. Do not use `quote_history`.
- **`quote.order` has no `ApiAware` flag on either version.** Never add `criteria.addAssociation('order')`. Get order dates by reading `quote.orderId` and then searching the core `order` repository by those ids.
- **Every field on `QuoteDecisionRecord` carries `#[Protection(write: [Protection::SYSTEM_SCOPE])]`.** New fields must too, and writes go through `Context::createDefaultContext()`.
- **Meteor badge/banner variants are `neutral`, `info`, `positive`, `critical`, `attention`.** `success` is not one and renders unstyled.
- **Failed reads are nulled, never zeroed.** A viewer without `quote:read` or `order:read` must see a figure absent, not a confident `0`.
- **Never let an audit write fail a merchant's state transition.** Subscribers use the nested try/catch shape already in `TerminalOutcomeSubscriber`.
- **PHP files stay under 400 physical lines** (`composer quality:filesize`).
- **Snippet files are `snippet/en.json` and `snippet/de.json`.** Both get every new key.
- **Run `composer format` before each PHP commit** — a pre-commit hook runs `mago fmt --check` on staged files and will reject unformatted code.

---

### Task 1: The `resolved_at` / `resolved_state` columns and their writer

**Files:**
- Create: `src/Migration/Migration1789000000AddEscalationResolution.php`
- Create: `src/Audit/EscalationResolutionWriterInterface.php`
- Create: `src/Audit/EscalationResolutionWriter.php`
- Modify: `src/Audit/QuoteDecisionRecord.php` (add two fields beside `terminalState` / `terminalAt`)
- Modify: `src/Resources/config/services.php` (register the writer)
- Test: `tests/Integration/EscalationResolutionWriterTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `EscalationResolutionWriterInterface::recordEscalationResolution(string $quoteId, string $state, \DateTimeImmutable $at): void`
  - `QuoteDecisionRecord::$resolvedAt` (`?\DateTimeImmutable`), `QuoteDecisionRecord::$resolvedState` (`?string`)

- [ ] **Step 1: Write the failing integration test**

Create `tests/Integration/EscalationResolutionWriterTest.php`. This mirrors `tests/Integration/TerminalOutcomeWriterTest.php` — read that file first for the `IntegrationTestCase` helpers it uses.

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Audit\EscalationResolutionWriter;
use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The writer against the real DAL. `resolved_at` and `resolved_state` are new
 * columns nothing else writes, so these tests are the proof they exist under
 * those names at the SQL level: a wrong column name fails here as a DAL write
 * error rather than as a silently dropped payload key.
 */
final class EscalationResolutionWriterTest extends IntegrationTestCase
{
    public function testItStampsTheNewestEscalatedRecord(): void
    {
        $quoteId = Uuid::randomHex();
        $older = Uuid::randomHex();
        $newer = Uuid::randomHex();

        self::records()->create([
            self::row($older, $quoteId, '2026-08-30 10:00:00.000', 'escalated'),
            self::row($newer, $quoteId, '2026-08-30 11:00:00.000', 'escalated'),
        ], Context::createDefaultContext());

        self::writer()->recordEscalationResolution($quoteId, 'sent', new \DateTimeImmutable('2026-08-30 15:00:00'));

        self::assertSame('sent', self::recordOf($newer)->resolvedState, 'The newest record was not stamped.');
        self::assertNotNull(self::recordOf($newer)->resolvedAt, 'resolvedAt stayed null on the stamped record.');
        self::assertNull(self::recordOf($older)->resolvedState, 'An older record was stamped as well.');
    }

    public function testItLeavesANonEscalatedNewestRecordAlone(): void
    {
        // The subscriber guards this too, but the writer is the thing that
        // touches the row, so it must not depend on its caller for
        // correctness: a pass that answered the buyer has no escalation to
        // resolve.
        $quoteId = Uuid::randomHex();
        $id = Uuid::randomHex();

        self::records()->create([
            self::row($id, $quoteId, '2026-08-30 10:00:00.000', 'offered'),
        ], Context::createDefaultContext());

        self::writer()->recordEscalationResolution($quoteId, 'sent', new \DateTimeImmutable('2026-08-30 15:00:00'));

        self::assertNull(self::recordOf($id)->resolvedState);
    }

    public function testAnAlreadyResolvedRecordIsNotRestamped(): void
    {
        // First resolution wins: the measure is "how long the deal desk took
        // to answer", so a later transition on the same quote must not reset
        // the clock.
        $quoteId = Uuid::randomHex();
        $id = Uuid::randomHex();

        self::records()->create([
            self::row($id, $quoteId, '2026-08-30 10:00:00.000', 'escalated'),
        ], Context::createDefaultContext());

        $writer = self::writer();
        $writer->recordEscalationResolution($quoteId, 'sent', new \DateTimeImmutable('2026-08-30 12:00:00'));
        $writer->recordEscalationResolution($quoteId, 'declined', new \DateTimeImmutable('2026-08-30 18:00:00'));

        self::assertSame('sent', self::recordOf($id)->resolvedState, 'The second transition overwrote the first.');
        self::assertSame(
            '2026-08-30 12:00:00',
            self::recordOf($id)->resolvedAt?->format('Y-m-d H:i:s'),
            'resolvedAt was moved by the second transition.',
        );
    }

    public function testAQuoteWithNoRecordsIsANoop(): void
    {
        self::writer()->recordEscalationResolution(Uuid::randomHex(), 'sent', new \DateTimeImmutable());

        $this->expectNotToPerformAssertions();
    }

    /** @return array<string, mixed> */
    private static function row(string $id, string $quoteId, string $createdAt, string $outcome): array
    {
        return [
            'id' => $id,
            'quoteId' => $quoteId,
            'outcome' => $outcome,
            'createdAt' => $createdAt,
        ];
    }

    private static function writer(): EscalationResolutionWriter
    {
        return new EscalationResolutionWriter(self::records());
    }

    /** @return EntityRepository<covariant QuoteDecisionRecord> */
    private static function records(): EntityRepository
    {
        return self::getContainer()->get('merchant_quote_agent_decision.repository');
    }

    private static function recordOf(string $id): QuoteDecisionRecord
    {
        $record = self::records()
            ->search(new Criteria([$id]), Context::createDefaultContext())
            ->first();

        self::assertInstanceOf(QuoteDecisionRecord::class, $record);

        return $record;
    }
}
```

Before running it, open `tests/Integration/TerminalOutcomeWriterTest.php` and reconcile the helper bodies above (`records()`, `recordOf()`) with how that file does it — if `IntegrationTestCase` already exposes an equivalent, use the existing one instead of redeclaring.

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:integration -- --filter EscalationResolutionWriterTest`

Expected: FAIL — `Class "MerchantQuoteAgentPlugin\Audit\EscalationResolutionWriter" not found`.

Note: `scripts/test-integration.sh` runs `docker exec` into `$SHOP_CONTAINER`; it cannot reach the remote shopdev hosts. If Docker is unavailable in your environment, say so and stop — do not fake this test.

- [ ] **Step 3: Write the migration**

Create `src/Migration/Migration1789000000AddEscalationResolution.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Override;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * When a human resolved an escalation, and what they moved the quote to.
 *
 * The third and fourth columns on this table not written by a servicing pass,
 * joining `terminal_state` / `terminal_at`: EscalationResolutionSubscriber
 * stamps them later, so a record's insert still has exactly one owner.
 *
 * SwagCommercial 7.13 has a `quote_history` table that would have made this
 * measurable retroactively with no new storage. 7.12 does not, and the plugin
 * supports both, so the plugin records it itself.
 */
class Migration1789000000AddEscalationResolution extends MigrationStep
{
    #[Override]
    public function getCreationTimestamp(): int
    {
        return 1789000000;
    }

    /** @throws DbalException */
    #[Override]
    public function update(Connection $connection): void
    {
        $columns = $connection->fetchFirstColumn(
            'SHOW COLUMNS FROM `merchant_quote_agent_decision` LIKE "resolved_%"',
        );

        if ($columns !== []) {
            return;
        }

        $connection->executeStatement(<<<'SQL'
                ALTER TABLE `merchant_quote_agent_decision`
                    ADD COLUMN `resolved_at`    DATETIME(3) NULL,
                    ADD COLUMN `resolved_state` VARCHAR(64) NULL;
            SQL);
    }

    #[Override]
    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive. The columns are additive and the merchant's data.
    }
}
```

- [ ] **Step 4: Add the entity fields**

In `src/Audit/QuoteDecisionRecord.php`, immediately after the `$terminalAt` property, add:

```php
    /**
     * When a human first acted on the quote after this pass escalated, and the
     * state they moved it to. Written by EscalationResolutionSubscriber, never
     * by a servicing pass.
     *
     * Any transition by anyone counts, including the buyer withdrawing the
     * quote: the core state-change event carries no author, and the only thing
     * that does — SwagCommercial's `quote_history` — does not exist on 7.12.
     * `resolvedState` is stored precisely so this stays inspectable.
     */
    #[Field(type: FieldType::DATETIME, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?\DateTimeImmutable $resolvedAt = null;

    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false], maxLength: 64)]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $resolvedState = null;
```

Also update the class docblock: the sentence "`terminalState` and `terminalAt` are the only two columns not written by a servicing pass" is now false. Change it to name all four, and keep the rest of the paragraph as is.

- [ ] **Step 5: Write the writer interface**

Create `src/Audit/EscalationResolutionWriterInterface.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

/**
 * Stamps the escalation resolution onto a quote's newest pass. An interface so
 * EscalationResolutionSubscriber is unit-testable without a DAL, exactly as
 * TerminalOutcomeWriterInterface does for TerminalOutcomeSubscriber.
 */
interface EscalationResolutionWriterInterface
{
    public function recordEscalationResolution(string $quoteId, string $state, \DateTimeImmutable $at): void;
}
```

- [ ] **Step 6: Write the writer**

Create `src/Audit/EscalationResolutionWriter.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * An escalation belongs to the pass that raised it, and that is the newest
 * pass at the moment a human acts: QuoteServicingTrigger only ever queues the
 * NEXT pass onto Messenger rather than running it inline, so no newer record
 * can exist yet. Same selection as TerminalOutcomeWriter, for the same reason.
 *
 * Two guards, both here rather than only in the subscriber, because this is
 * the thing that touches the row: a newest pass that did not escalate has no
 * escalation to resolve, and a pass already carrying `resolvedAt` must keep
 * its first resolution — the measure is how long the deal desk took to answer,
 * so a later transition on the same quote must not reset the clock.
 *
 * @phpstan-import-type QuoteDecisionRecord from QuoteDecisionRecord
 */
final readonly class EscalationResolutionWriter implements EscalationResolutionWriterInterface
{
    private const ESCALATED = 'escalated';

    /** @param EntityRepository<covariant QuoteDecisionRecord> $records */
    public function __construct(
        private EntityRepository $records,
    ) {}

    #[\Override]
    public function recordEscalationResolution(string $quoteId, string $state, \DateTimeImmutable $at): void
    {
        // System scope, because every field carries
        // Protection(write: [Protection::SYSTEM_SCOPE]).
        $context = Context::createDefaultContext();

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('quoteId', $quoteId));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
        $criteria->addSorting(new FieldSorting('id', FieldSorting::DESCENDING));
        $criteria->setLimit(1);

        $newest = $this->records->search($criteria, $context)->first();

        if (!$newest instanceof QuoteDecisionRecord) {
            return;
        }

        if ($newest->outcome !== self::ESCALATED || $newest->resolvedAt !== null) {
            return;
        }

        $this->records->update([[
            'id' => $newest->id,
            'resolvedAt' => $at,
            'resolvedState' => $state,
        ]], $context);
    }
}
```

Remove the `@phpstan-import-type` line if `composer typecheck` objects to it — it is not load-bearing.

- [ ] **Step 7: Register the writer**

In `src/Resources/config/services.php`, beside the existing `TerminalOutcomeWriter` registration (around line 274), add:

```php
    $services->set(EscalationResolutionWriter::class)->args([service('merchant_quote_agent_decision.repository')]);
    $services->alias(EscalationResolutionWriterInterface::class, EscalationResolutionWriter::class);
```

Add the two matching `use` statements at the top of the file, in alphabetical order among the other `MerchantQuoteAgentPlugin\Audit\...` imports.

- [ ] **Step 8: Run the test to verify it passes**

Run: `composer test:integration -- --filter EscalationResolutionWriterTest`
Expected: PASS, 4 tests.

If the migration did not run, the DAL write fails on an unknown column. Re-run the plugin's migrations in the container (`bin/console database:migrate --all MerchantQuoteAgentPlugin`) and try again.

- [ ] **Step 9: Run the quality gate and commit**

```bash
composer format
composer lint && composer typecheck && composer quality:filesize
git add src/Migration/Migration1789000000AddEscalationResolution.php \
        src/Audit/EscalationResolutionWriter.php \
        src/Audit/EscalationResolutionWriterInterface.php \
        src/Audit/QuoteDecisionRecord.php \
        src/Resources/config/services.php \
        tests/Integration/EscalationResolutionWriterTest.php
git commit -m "feat(audit): record when a human resolved an escalation"
```

---

### Task 2: The `EscalationResolutionSubscriber`

**Files:**
- Create: `src/Audit/EscalationResolutionSubscriber.php`
- Create: `tests/Unit/Audit/FakeEscalationResolutionWriter.php`
- Create: `tests/Unit/Audit/EscalationResolutionSubscriberTest.php`
- Modify: `src/Resources/config/services.php` (register the subscriber)

**Interfaces:**
- Consumes: `EscalationResolutionWriterInterface::recordEscalationResolution(string $quoteId, string $state, \DateTimeImmutable $at): void` (Task 1)
- Produces: `EscalationResolutionSubscriber::getSubscribedEvents(): array<string, string>` returning `['state_machine.quote.state_changed' => 'onQuoteStateChanged']`, and `onQuoteStateChanged(StateMachineStateChangeEvent $event): void`

- [ ] **Step 1: Write the fake writer**

Create `tests/Unit/Audit/FakeEscalationResolutionWriter.php`, modelled on the existing `tests/Unit/Audit/FakeTerminalOutcomeWriter.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\EscalationResolutionWriterInterface;

/** Captures what the subscriber decided to record, and can be told to fail. */
final class FakeEscalationResolutionWriter implements EscalationResolutionWriterInterface
{
    /** @var list<array{quoteId: string, state: string, at: \DateTimeImmutable}> */
    public array $calls = [];

    public ?\Throwable $throws = null;

    #[\Override]
    public function recordEscalationResolution(string $quoteId, string $state, \DateTimeImmutable $at): void
    {
        $this->calls[] = ['quoteId' => $quoteId, 'state' => $state, 'at' => $at];

        if ($this->throws !== null) {
            throw $this->throws;
        }
    }
}
```

- [ ] **Step 2: Write the failing subscriber test**

Create `tests/Unit/Audit/EscalationResolutionSubscriberTest.php`. Read `tests/Unit/Audit/TerminalOutcomeSubscriberTest.php` first — it is the template, and `QuoteTriggerEventFixture::stateEvent()` is the event builder. Its signature is:

```php
QuoteTriggerEventFixture::stateEvent(
    string $nextState,
    string $side = StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER,
    ?Context $context = null,
    string $fromState = 'draft',
    string $transitionName = 'customer_send',
): StateMachineStateChangeEvent
```

It always uses quote id `'q1'`.

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\EscalationResolutionSubscriber;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\QuoteTriggerEventFixture;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;

final class EscalationResolutionSubscriberTest extends TestCase
{
    public function testItSubscribesToTheCoreStateChangeEvent(): void
    {
        self::assertSame(
            ['state_machine.quote.state_changed' => 'onQuoteStateChanged'],
            EscalationResolutionSubscriber::getSubscribedEvents(),
        );
    }

    /**
     * ANY state a quote can be moved into resolves an open escalation — the
     * deal desk sending a revised offer, declining it, the buyer withdrawing
     * it. The writer decides whether there is an escalation to resolve; the
     * subscriber's job is only to report that something happened.
     */
    public function testEveryEnteredStateIsReported(): void
    {
        foreach (['sent', 'declined', 'accepted', 'in_review', 'withdrawn'] as $state) {
            $writer = new FakeEscalationResolutionWriter();
            self::subscriber($writer)->onQuoteStateChanged(QuoteTriggerEventFixture::stateEvent($state));

            self::assertCount(1, $writer->calls, sprintf('Entering "%s" reported nothing.', $state));
            self::assertSame('q1', $writer->calls[0]['quoteId']);
            self::assertSame($state, $writer->calls[0]['state']);

            // Seconds, not milliseconds: this must not be flaky.
            $secondsAgo = time() - $writer->calls[0]['at']->getTimestamp();
            self::assertLessThan(5, abs($secondsAgo), 'The recorded timestamp is not close to now.');
        }
    }

    /** The event fires twice per transition, leave then enter. Only entering is news. */
    public function testTheLeaveSideReportsNothing(): void
    {
        $writer = new FakeEscalationResolutionWriter();

        self::subscriber($writer)->onQuoteStateChanged(QuoteTriggerEventFixture::stateEvent(
            'sent',
            StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_LEAVE,
            fromState: 'in_review',
        ));

        self::assertSame([], $writer->calls);
    }

    /** A snapshot-lane transition is a mirror, never a merchant's decision. */
    public function testATransitionOnTheSnapshotVersionReportsNothing(): void
    {
        $writer = new FakeEscalationResolutionWriter();

        self::subscriber($writer)->onQuoteStateChanged(QuoteTriggerEventFixture::stateEvent(
            'sent',
            context: new Context(new SystemSource(), versionId: Uuid::randomHex()),
        ));

        self::assertSame([], $writer->calls);
    }

    /**
     * A merchant clicking "send offer" must never see a 500 because an audit
     * write failed.
     */
    public function testAThrowingWriterIsSwallowedAndLogged(): void
    {
        $writer = new FakeEscalationResolutionWriter();
        $writer->throws = new \RuntimeException('the DAL is unwell');

        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            /**
             * @param mixed $level
             * @param array<string, mixed> $context
             */
            public function log($level, $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };

        self::subscriber($writer, $logger)->onQuoteStateChanged(QuoteTriggerEventFixture::stateEvent('sent'));

        self::assertCount(1, $logger->messages, 'The failure was not logged.');
    }

    /**
     * A logger that itself misbehaves must not fail the quote's transition
     * either: a failing DAL write is exactly the situation most likely to come
     * with a failing log handler.
     */
    public function testAThrowingLoggerIsSwallowed(): void
    {
        $writer = new FakeEscalationResolutionWriter();
        $writer->throws = new \RuntimeException('the DAL is unwell');

        $logger = new class extends AbstractLogger {
            /**
             * @param mixed $level
             * @param array<string, mixed> $context
             */
            public function log($level, $message, array $context = []): void
            {
                throw new \RuntimeException('the logger is unwell too');
            }
        };

        self::subscriber($writer, $logger)->onQuoteStateChanged(QuoteTriggerEventFixture::stateEvent('sent'));

        $this->expectNotToPerformAssertions();
    }

    private static function subscriber(
        FakeEscalationResolutionWriter $writer,
        ?LoggerInterface $logger = null,
    ): EscalationResolutionSubscriber {
        return new EscalationResolutionSubscriber($writer, $logger ?? new NullLogger());
    }
}
```

Cross-check the snapshot-version test and the two throwing tests against how `TerminalOutcomeSubscriberTest` builds them — if that file already has a working idiom for the non-live `Context` or the anonymous logger, copy it verbatim rather than the version above.

- [ ] **Step 3: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter EscalationResolutionSubscriberTest`
Expected: FAIL — `Class "MerchantQuoteAgentPlugin\Audit\EscalationResolutionSubscriber" not found`.

- [ ] **Step 4: Write the subscriber**

Create `src/Audit/EscalationResolutionSubscriber.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use Psr\Log\LoggerInterface;
use Shopware\Core\Defaults;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * How long the deal desk took to answer an escalated quote.
 *
 * A sibling of TerminalOutcomeSubscriber on the same core event, and
 * deliberately not folded into it: that one reports the five states that END a
 * negotiation, this one reports EVERY state, because the transition that
 * resolves an escalation is usually `sent` — the merchant putting a revised
 * offer in front of the buyer — which is not terminal at all.
 *
 * QuoteEscalator does not transition the quote when it escalates. It writes a
 * customFields marker and a buyer-facing comment, and the human then acts in
 * SwagCommercial's own quote admin, so the resolution can only be observed
 * from the quote's state machine.
 *
 * No state filter, therefore, and no author filter either: the core event
 * carries no author. Any transition by anyone closes the escalation, including
 * a buyer withdrawing the quote. `resolvedState` is stored so that stays
 * inspectable. The only thing that would distinguish the deal desk from the
 * buyer is SwagCommercial's `quote_history`, which does not exist on 7.12.
 *
 * Which pass is stamped, and whether one is stamped at all, is
 * EscalationResolutionWriter's decision — it holds both guards, because it is
 * the thing that touches the row.
 *
 * NO AgentContext::STATE guard, for the same reason TerminalOutcomeSubscriber
 * has none: the agent drives `process` and `sent`, and a `sent` driven by
 * ReplyComposer means the agent answered the buyer itself, so the newest pass
 * answered rather than escalated and the writer's guard already discards it.
 */
final readonly class EscalationResolutionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EscalationResolutionWriterInterface $writer,
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

        $quoteId = $event->getTransition()->getEntityId();

        // Same two-layer guard as TerminalOutcomeSubscriber, for the same
        // reason: a merchant clicking "send offer" must never see a 500
        // because an audit write failed, and a throwing logger must not fail
        // the transition either.
        try {
            try {
                $this->writer->recordEscalationResolution($quoteId, $event->getStateName(), new \DateTimeImmutable());
            } catch (\Throwable $e) {
                $this->logger->error('The escalation resolution could not be recorded.', [
                    'quoteId' => $quoteId,
                    'resolvedState' => $event->getStateName(),
                    'exception' => $e,
                ]);
            }
        } catch (\Throwable) {
            // @mago-expect lint:no-empty-catch-clause
            // Deliberately empty: a logger that throws must not fail the
            // quote's state transition, and there is nowhere left to report
            // the failure.
        }
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter EscalationResolutionSubscriberTest`
Expected: PASS, 6 tests.

- [ ] **Step 6: Register the subscriber**

In `src/Resources/config/services.php`, beside `$services->set(TerminalOutcomeSubscriber::class);`, add:

```php
    $services->set(EscalationResolutionSubscriber::class);
```

`LoggerInterface` is autowired here, as the comment near line 241 notes for `TerminalOutcomeSubscriber`.

- [ ] **Step 7: Prove the subscriber is actually wired**

`tests/Integration/TerminalOutcomeSubscriptionTest.php` proves the sibling subscriber is registered against the real event dispatcher. Open it, and add the equivalent case for this subscriber in the same file, following whatever assertion style it already uses (typically: fetch the dispatcher from the container and assert the listener is present for `state_machine.quote.state_changed`).

Run: `composer test:integration -- --filter TerminalOutcomeSubscriptionTest`
Expected: PASS.

- [ ] **Step 8: Run the full unit suite and commit**

```bash
composer format
vendor/bin/phpunit
composer lint && composer typecheck && composer quality:filesize
git add src/Audit/EscalationResolutionSubscriber.php \
        src/Resources/config/services.php \
        tests/Unit/Audit/FakeEscalationResolutionWriter.php \
        tests/Unit/Audit/EscalationResolutionSubscriberTest.php \
        tests/Integration/TerminalOutcomeSubscriptionTest.php
git commit -m "feat(audit): stamp escalation resolution on quote state change"
```

---

### Task 3: The escalation SLA config field

**Files:**
- Modify: `src/Resources/config/config.xml` (add one field to the *Negotiation policies* card)
- Test: `tests/Integration/PluginConfigTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: system config key `MerchantQuoteAgentPlugin.config.escalationSlaHours` (float, no default). `QuoteAgentSettingsReader::DOMAIN` is the string `'MerchantQuoteAgentPlugin.config.'`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Integration/PluginConfigTest.php`. Read the file first: it has a `self::systemConfig()` helper returning `SystemConfigService`, and note the caveat in `test-shop-live-config-vs-test-defaults` — a shop with live configuration makes assertions about install-time defaults fail, so assert *presence and type*, not a default value.

```php
    /**
     * The SLA benchmarks the dashboard's escalation resolution time and
     * steers nothing in the pipeline, which is why it is deliberately absent
     * from QuoteAgentSettings — see testTheSlaDoesNotReachTheGuardrailEngine.
     */
    public function testTheEscalationSlaIsConfigurable(): void
    {
        $config = self::systemConfig();
        $key = QuoteAgentSettingsReader::DOMAIN . 'escalationSlaHours';

        $config->set($key, 24.0);

        self::assertSame(24.0, $config->get($key));
    }

    /**
     * The SLA must not become a guardrail. It is a reporting benchmark, so a
     * shop that sets it must not thereby change what the agent does.
     */
    public function testTheSlaDoesNotReachTheGuardrailEngine(): void
    {
        self::assertStringNotContainsString(
            'escalationSlaHours',
            (string) file_get_contents(__DIR__ . '/../../src/Config/QuoteAgentSettingsReader.php'),
        );
        self::assertStringNotContainsString(
            'escalationSlaHours',
            (string) file_get_contents(__DIR__ . '/../../src/Config/QuoteAgentSettings.php'),
        );
    }
```

Add `use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsReader;` to the file's imports if it is not already there.

- [ ] **Step 2: Run the test to verify the first case fails**

Run: `composer test:integration -- --filter PluginConfigTest`
Expected: `testTheEscalationSlaIsConfigurable` FAILS (the key is not declared, so `get()` returns `null` rather than the float). `testTheSlaDoesNotReachTheGuardrailEngine` passes already, which is correct — it is a guard against a future regression.

- [ ] **Step 3: Add the config field**

In `src/Resources/config/config.xml`, inside the `<card>` whose title is `Negotiation policies`, after the `validityDays` field:

```xml
        <input-field type="float">
            <name>escalationSlaHours</name>
            <label>Escalation SLA (hours)</label>
            <helpText>How long the deal desk has to answer an escalated quote. Used only to benchmark the dashboard's escalation resolution time — it does not change what the agent does or what it may grant. Blank means no benchmark.</helpText>
        </input-field>
```

No `<defaultValue>`: a guessed SLA would put a verdict on the dashboard the merchant never chose.

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:integration -- --filter PluginConfigTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Resources/config/config.xml tests/Integration/PluginConfigTest.php
git commit -m "feat(config): add the escalation SLA used to benchmark the dashboard"
```

---

### Task 4: Drain the needs-review queue

This is the bug fix, and it stands on its own: after this task the dashboard's existing grid is correct even before any new tile exists.

**Files:**
- Modify: `src/Resources/app/administration/src/module/merchant-quote-agent/decision.ts`
- Modify: `src/Resources/app/administration/src/module/merchant-quote-agent/decision.check.mjs`
- Modify: `src/Resources/app/administration/src/module/merchant-quote-agent/snippet/en.json`
- Modify: `src/Resources/app/administration/src/module/merchant-quote-agent/snippet/de.json`

**Interfaces:**
- Consumes: `QuoteDecisionRecord::$resolvedAt` (Task 1) — reaches the JS as `resolvedAt` on each pass, an ISO string or `null`.
- Produces:
  - `disposition(outcome: string | null, terminalState: string | null = null, resolvedAt: string | null = null): string`
  - `CLOSED_NO_DEAL: string[]` — exported
  - `DISPOSITION_CLASSES` gains `'closedNoDeal'`
  - `foldToQuotes()` entries gain `escalated: boolean`, `escalatedAt: string | null`, `resolvedAt: string | null`, `latestAnswered: object | null`

- [ ] **Step 1: Fix the assertion that pins the bug, and add the new cases**

In `decision.check.mjs`, **replace** the line

```js
assert.equal(disposition('escalated', 'declined'), 'needsReview');
```

with the block below, and add the rest of these assertions beside the existing disposition cases:

```js
// A terminal state always outranks the pass outcome. Before this, only
// `accepted` did, so an escalated quote that ended declined, expired,
// cancelled or withdrawn read "Needs review" forever — and the grid now
// defaults to that filter.
assert.equal(disposition('escalated', 'declined'), 'closedNoDeal');
assert.equal(disposition('escalated', 'expired'), 'closedNoDeal');
assert.equal(disposition('escalated', 'cancelled'), 'closedNoDeal');
assert.equal(disposition('escalated', 'withdrawn'), 'closedNoDeal');
assert.equal(disposition('offered', 'declined'), 'closedNoDeal');
assert.equal(disposition('nothing_to_do', 'expired'), 'closedNoDeal');

// An escalation a human has answered is waiting on the buyer, not on the
// merchant. It leaves the review queue without needing a class of its own.
assert.equal(disposition('escalated', null, null), 'needsReview');
assert.equal(disposition('escalated', null, '2026-09-09T10:00:00.000+00:00'), 'awaitingBuyer');

// An accepted quote is an order regardless of how it got there.
assert.equal(disposition('escalated', 'accepted', '2026-09-09T10:00:00.000+00:00'), 'orderPlaced');
```

The existing `assert.equal(disposition('offered', 'expired'), 'answered')`, `('offered', 'cancelled')` and `('offered', 'withdrawn')` assertions all now expect `closedNoDeal` — update them too rather than leaving contradictory cases in the file.

Then add the fold assertions, near the existing `foldToQuotes` cases:

```js
// `escalated` is true if ANY pass escalated, not just the latest: a quote
// that escalated in round one and was answered in round two did require a
// human, and the auto-execution rate has to count it.
const twoRounds = foldToQuotes([
    { id: 'p2', quoteId: 'q9', outcome: 'offered', totalNetBefore: 100, totalNetAfter: 95, createdAt: '2026-09-02T10:00:00.000+00:00' },
    { id: 'p1', quoteId: 'q9', outcome: 'escalated', totalNetBefore: 100, createdAt: '2026-09-01T10:00:00.000+00:00' },
]);

assert.equal(twoRounds.length, 1);
assert.equal(twoRounds[0].escalated, true);
assert.equal(twoRounds[0].escalatedAt, '2026-09-01T10:00:00.000+00:00');
assert.equal(twoRounds[0].disposition, 'answered');
assert.equal(twoRounds[0].latestAnswered.id, 'p2');

// A quote that only ever escalated has no answered pass at all, which is
// what excludes it from price retention.
const onlyEscalated = foldToQuotes([
    { id: 'p1', quoteId: 'q10', outcome: 'escalated', totalNetBefore: 100, createdAt: '2026-09-01T10:00:00.000+00:00', resolvedAt: '2026-09-01T14:00:00.000+00:00' },
]);

assert.equal(onlyEscalated[0].latestAnswered, null);
assert.equal(onlyEscalated[0].resolvedAt, '2026-09-01T14:00:00.000+00:00');
assert.equal(onlyEscalated[0].disposition, 'awaitingBuyer');

// Every class maps to a real Meteor variant. `success` is NOT one of them and
// renders unstyled, which is the regression this guards.
const METEOR_VARIANTS = ['neutral', 'info', 'positive', 'critical', 'attention'];

DISPOSITION_CLASSES.forEach((key) => {
    assert.ok(
        METEOR_VARIANTS.includes(dispositionVariant(key)),
        `disposition "${key}" maps to "${dispositionVariant(key)}", which is not a Meteor variant`,
    );
});
assert.equal(dispositionVariant('closedNoDeal'), 'neutral');
assert.ok(DISPOSITION_CLASSES.includes('closedNoDeal'));
```

- [ ] **Step 2: Run the check to verify it fails**

Run: `node src/Resources/app/administration/src/module/merchant-quote-agent/decision.check.mjs`
Expected: FAIL with an `AssertionError` — expected `'closedNoDeal'`, got `'needsReview'`.

- [ ] **Step 3: Fix `disposition()`**

In `decision.ts`, replace the `disposition` function and add the new constant above it:

```ts
/**
 * The four terminal states that are not a sale. Mirrors the non-`accepted`
 * half of TerminalOutcomeSubscriber::TERMINAL_STATES — the two lists must move
 * together, and cannot be shared across the PHP/JS boundary.
 */
export const CLOSED_NO_DEAL = ['declined', 'expired', 'cancelled', 'withdrawn'];

export function disposition(
    outcome: string | null,
    terminalState: string | null = null,
    resolvedAt: string | null = null,
): string {
    if (terminalState === ORDER_PLACED_TERMINAL_STATE) {
        return 'orderPlaced';
    }

    // ANY terminal state outranks the pass outcome, not just `accepted`. Only
    // `accepted` did, and the result was that an escalated quote which ended
    // declined, expired, cancelled or withdrawn read "Needs review" forever —
    // a queue that could never drain, on the grid's default filter.
    if (terminalState !== null && CLOSED_NO_DEAL.includes(terminalState)) {
        return 'closedNoDeal';
    }

    // An escalation a human has answered is waiting on the BUYER, which is
    // literally true once the merchant has replied. No class of its own is
    // needed, and the auto-execution rate still counts the quote against the
    // agent because that reads the `escalated` flag rather than this.
    if (outcome === 'escalated') {
        return resolvedAt ? 'awaitingBuyer' : 'needsReview';
    }

    return (outcome && DISPOSITIONS[outcome]) || 'other';
}
```

Also update the `DISPOSITIONS` docblock: `escalated: 'needsReview'` is no longer the whole story. Leave the map entry in place (it is now unreachable for `escalated`, so **delete** that one entry to avoid two sources of truth) and say in the comment that `escalated` is decided above, because it depends on `resolvedAt`.

Then extend the class list and the variant map:

```ts
export const DISPOSITION_CLASSES = ['orderPlaced', 'closedNoDeal', 'needsReview', 'answered', 'awaitingBuyer', 'noAction', 'other'];
```

```ts
const DISPOSITION_VARIANTS: Record<string, string> = {
    orderPlaced: 'positive',
    answered: 'positive',
    needsReview: 'critical',
    awaitingBuyer: 'info',
    closedNoDeal: 'neutral',
    noAction: 'neutral',
    other: 'neutral',
};
```

- [ ] **Step 4: Carry the new values through `foldToQuotes`**

Still in `decision.ts`, in `foldToQuotes`, the `seen` branch and the `byQuote.set` branch both change. Replace the whole function body's two branches with:

```ts
        if (seen) {
            seen.rounds += 1;
            // The quote's own value, not the latest pass's: a later pass can
            // start from an already-discounted total.
            seen.netBefore = Math.max(seen.netBefore, netBefore);
            // Any pass, not just the newest. TerminalOutcomeWriter stamps the
            // newest record at the time of the transition, and a pass that was
            // already in flight then inserts a newer one behind it — so
            // reading only `latest` loses the outcome on exactly the quote that
            // was cancelled or accepted mid-pass.
            seen.terminalState = seen.terminalState ?? decision.terminalState ?? null;
            seen.terminalAt = seen.terminalAt ?? decision.terminalAt ?? null;
            // Any pass, for the same reason plus one of its own: a quote that
            // escalated in round one and was answered in round two DID need a
            // human, so the auto-execution rate must count it.
            seen.escalated = seen.escalated || decision.outcome === 'escalated';
            // The newest escalated pass, since decisions arrive newest-first.
            // That is also the pass `disposition` asks about, because it only
            // consults `resolvedAt` when the LATEST pass escalated.
            if (decision.outcome === 'escalated' && seen.escalatedAt === null) {
                seen.escalatedAt = decision.createdAt ?? null;
                seen.resolvedAt = decision.resolvedAt ?? null;
            }
            // The newest pass that put an offer in front of the buyer. Price
            // retention needs the price the buyer actually saw, and the latest
            // pass may have escalated without offering anything.
            seen.latestAnswered = seen.latestAnswered ?? (answeredTheBuyer(decision.outcome) ? decision : null);
            seen.disposition = disposition(seen.latest.outcome, seen.terminalState, seen.resolvedAt);

            return;
        }

        const escalated = decision.outcome === 'escalated';

        byQuote.set(decision.quoteId, {
            quoteId: decision.quoteId,
            quoteNumber: decision.quoteNumber,
            latest: decision,
            latestAnswered: answeredTheBuyer(decision.outcome) ? decision : null,
            rounds: 1,
            netBefore,
            terminalState: decision.terminalState ?? null,
            terminalAt: decision.terminalAt ?? null,
            escalated,
            escalatedAt: escalated ? decision.createdAt ?? null : null,
            resolvedAt: escalated ? decision.resolvedAt ?? null : null,
            disposition: disposition(
                decision.outcome,
                decision.terminalState ?? null,
                escalated ? decision.resolvedAt ?? null : null,
            ),
        });
```

- [ ] **Step 5: Run the check to verify it passes**

Run: `node src/Resources/app/administration/src/module/merchant-quote-agent/decision.check.mjs`
Expected: no output and exit code 0. Confirm with `echo $?`.

- [ ] **Step 6: Add the snippet**

In `snippet/en.json`, under `merchant-quote-agent.disposition`:

```json
        "closedNoDeal": "Closed, no deal",
```

In `snippet/de.json`, the same key with the German wording matching the file's existing tone for the other disposition labels.

- [ ] **Step 7: Commit**

```bash
git add src/Resources/app/administration/src/module/merchant-quote-agent/decision.ts \
        src/Resources/app/administration/src/module/merchant-quote-agent/decision.check.mjs \
        src/Resources/app/administration/src/module/merchant-quote-agent/snippet/en.json \
        src/Resources/app/administration/src/module/merchant-quote-agent/snippet/de.json
git commit -m "fix(admin): let the needs-review queue drain"
```

---

### Task 5: The four measures as pure functions

**Files:**
- Create: `src/Resources/app/administration/src/module/merchant-quote-agent/measures.ts`
- Create: `src/Resources/app/administration/src/module/merchant-quote-agent/measures.check.mjs`

**A note on the file split.** The spec says the measures live in `decision.ts`. They get their own module instead: `decision.ts` is already 428 lines and the house gate targets ~400, so folding four measures plus their helpers into it would push it past 600. `measures.ts` imports `answeredTheBuyer` from `decision.ts`; nothing flows the other way.

**Interfaces:**
- Consumes: `foldToQuotes()` output from Task 4 (`.escalated`, `.netBefore`, `.latestAnswered`, `.disposition`), and raw decision passes (`.outcome`, `.createdAt`, `.resolvedAt`).
- Produces:
  - `autoExecutionRate(quotes): { rate: number | null, escalated: number, total: number }`
  - `escalationResolution(passes, slaHours): { meanMs: number | null, measured: number, open: number, withinSla: number | null }`
  - `splitDeals(quoteRows, orderDates, foldedByQuoteId): { agent: Deal[], baseline: Deal[] }`
  - `valueRange(deals): { min: number, max: number } | null`
  - `withinRange(deals, range): Deal[]`
  - `priceRetention(agent, baseline): { agentDiscount: number | null, baselineDiscount: number | null, comparable: number }`
  - `dealCycleTime(agent, baseline): { agentMs: number | null, baselineMs: number | null, comparable: number }`
  - `formatSpan(ms): string`
  - A `Deal` is `{ quoteId, amountNet, submittedAt, confirmedAt, agentDiscount, baselineDiscount }`.

- [ ] **Step 1: Write the failing check script**

Create `measures.check.mjs`:

```js
/**
 * Self-check for measures.ts. Same arrangement as decision.check.mjs — no test
 * runner, because the project has no JS toolchain and these are pure
 * functions.
 *
 *     node src/Resources/app/administration/src/module/merchant-quote-agent/measures.check.mjs
 */

import assert from 'node:assert/strict';
import {
    autoExecutionRate,
    dealCycleTime,
    escalationResolution,
    formatSpan,
    priceRetention,
    splitDeals,
    valueRange,
    withinRange,
} from './measures.ts';
import { foldToQuotes } from './decision.ts';

const iso = (day, hour = 0) => `2026-09-${String(day).padStart(2, '0')}T${String(hour).padStart(2, '0')}:00:00.000+00:00`;

// ---------------------------------------------------------------- auto-execution

// The denominator is every quote serviced, not only concluded ones.
// Restricting it to concluded negotiations would exclude unresolved
// escalations, so a shop with ten quotes stuck in the review queue would
// report 100% auto-execution.
assert.deepEqual(autoExecutionRate([]), { rate: null, escalated: 0, total: 0 });
assert.deepEqual(
    autoExecutionRate([{ escalated: false }, { escalated: false }, { escalated: true }, { escalated: false }]),
    { rate: 75, escalated: 1, total: 4 },
);
assert.deepEqual(autoExecutionRate([{ escalated: true }]), { rate: 0, escalated: 1, total: 1 });

// ------------------------------------------------------------------- escalations

const passes = [
    // resolved in 4 hours
    { outcome: 'escalated', createdAt: iso(1, 10), resolvedAt: iso(1, 14) },
    // resolved in 8 hours
    { outcome: 'escalated', createdAt: iso(2, 8), resolvedAt: iso(2, 16) },
    // still open
    { outcome: 'escalated', createdAt: iso(3, 9), resolvedAt: null },
    // not an escalation at all
    { outcome: 'offered', createdAt: iso(3, 9), resolvedAt: null },
];

const sixHours = 6 * 3600000;

assert.deepEqual(escalationResolution(passes, null), {
    meanMs: sixHours,
    measured: 2,
    open: 1,
    withinSla: null,
});

// One of the two measured escalations beat a 6-hour SLA.
assert.deepEqual(escalationResolution(passes, 6), {
    meanMs: sixHours,
    measured: 2,
    open: 1,
    withinSla: 1,
});

assert.deepEqual(escalationResolution([], 24), { meanMs: null, measured: 0, open: 0, withinSla: 0 });

// An unresolved escalation reads as open, never as zero duration.
assert.deepEqual(escalationResolution([{ outcome: 'escalated', createdAt: iso(1, 10), resolvedAt: null }], 24), {
    meanMs: null,
    measured: 0,
    open: 1,
    withinSla: 0,
});

// A resolvedAt before the escalation is corrupt, not a negative duration.
assert.equal(
    escalationResolution([{ outcome: 'escalated', createdAt: iso(2, 10), resolvedAt: iso(1, 10) }], null).measured,
    0,
);

// ------------------------------------------------------------------------ deals

const folded = foldToQuotes([
    { id: 'p1', quoteId: 'agent-1', outcome: 'offered', totalNetBefore: 1000, totalNetAfter: 900, terminalState: 'accepted', createdAt: iso(1, 12) },
    { id: 'p2', quoteId: 'agent-2', outcome: 'escalated', totalNetBefore: 5000, terminalState: 'accepted', createdAt: iso(2, 12) },
]);
const foldedByQuote = new Map(folded.map((quote) => [quote.quoteId, quote]));

const quoteRows = [
    // agent-negotiated: 10% off, RFQ to order in 24h
    { id: 'agent-1', amountNet: 900, requestedAt: iso(1, 10), createdAt: iso(1, 9), orderId: 'o1', totalDiscount: 0, totalLineItemDiscount: 100 },
    // agent-touched but only ever escalated: a human set the price
    { id: 'agent-2', amountNet: 5000, requestedAt: iso(2, 10), createdAt: iso(2, 9), orderId: 'o2', totalDiscount: 0, totalLineItemDiscount: 0 },
    // untouched baseline, inside the agent value range: 20% off, 72h
    { id: 'base-1', amountNet: 800, requestedAt: iso(1, 10), createdAt: iso(1, 9), orderId: 'o3', totalDiscount: 200, totalLineItemDiscount: 0 },
    // untouched baseline, far outside the agent value range
    { id: 'base-2', amountNet: 90000, requestedAt: iso(1, 10), createdAt: iso(1, 9), orderId: 'o4', totalDiscount: 45000, totalLineItemDiscount: 0 },
];

const orderDates = new Map([
    ['o1', iso(2, 10)],
    ['o2', iso(3, 10)],
    ['o3', iso(4, 10)],
    ['o4', iso(2, 10)],
]);

const { agent, baseline } = splitDeals(quoteRows, orderDates, foldedByQuote);

assert.deepEqual(agent.map((d) => d.quoteId), ['agent-1', 'agent-2']);
assert.deepEqual(baseline.map((d) => d.quoteId), ['base-1', 'base-2']);

// requestedAt wins over createdAt where it exists.
assert.equal(agent[0].submittedAt, iso(1, 10));
// ...and falls back where it does not, which is every quote on 7.12.
assert.equal(
    splitDeals([{ id: 'x', amountNet: 10, createdAt: iso(5, 8), orderId: null }], new Map(), new Map())
        .baseline[0].submittedAt,
    iso(5, 8),
);

// The agent's discount comes from our own records, so it is version-proof.
assert.equal(agent[0].agentDiscount, 10);
// A quote that escalated and was answered by a human has no answered pass, so
// there is no agent-set price to measure.
assert.equal(agent[1].agentDiscount, null);
// The baseline's discount comes from the quote's own fields.
assert.equal(baseline[0].baselineDiscount, 20);
// A missing totalLineItemDiscount is 0, not NaN — that field does not exist on
// SwagCommercial 7.12.
assert.equal(
    splitDeals([{ id: 'y', amountNet: 90, totalDiscount: 10, orderId: null }], new Map(), new Map())
        .baseline[0].baselineDiscount,
    10,
);

// ----------------------------------------------------------------- value range

// The range is read off amountNet on BOTH sides. Reading the agent side from
// the decision record's pre-negotiation netBefore would bias the baseline
// upward by exactly the discount being measured.
assert.deepEqual(valueRange(agent), { min: 900, max: 5000 });
assert.equal(valueRange([]), null);
assert.deepEqual(withinRange(baseline, valueRange(agent)).map((d) => d.quoteId), []);
assert.deepEqual(withinRange(baseline, { min: 100, max: 1000 }).map((d) => d.quoteId), ['base-1']);
// No agent deals means no range, and comparing against everything would be a
// lie rather than a fallback.
assert.deepEqual(withinRange(baseline, null), []);

// -------------------------------------------------------------- the two ratios

// base-1 is 800 net, below the agent range's 900 floor, so nothing is
// comparable and the baseline is unavailable rather than misleading.
assert.deepEqual(priceRetention(agent, baseline), {
    agentDiscount: 10,
    baselineDiscount: null,
    comparable: 0,
});

const wideAgent = [...agent, { quoteId: 'agent-3', amountNet: 700, submittedAt: iso(1, 10), confirmedAt: iso(1, 22), agentDiscount: 4, baselineDiscount: null }];

assert.deepEqual(priceRetention(wideAgent, baseline), {
    agentDiscount: 7,
    baselineDiscount: 20,
    comparable: 1,
});

// agent-1 took 24h; agent-2 took 24h; agent-3 took 12h.
assert.deepEqual(dealCycleTime(agent, baseline), {
    agentMs: 24 * 3600000,
    baselineMs: null,
    comparable: 0,
});
assert.deepEqual(dealCycleTime(wideAgent, baseline), {
    agentMs: 20 * 3600000,
    baselineMs: 72 * 3600000,
    comparable: 1,
});

// A deal with no order date has no cycle time and must not read as zero.
assert.equal(
    dealCycleTime([{ quoteId: 'z', amountNet: 100, submittedAt: iso(1, 10), confirmedAt: null, agentDiscount: null, baselineDiscount: null }], []).agentMs,
    null,
);
assert.deepEqual(dealCycleTime([], []), { agentMs: null, baselineMs: null, comparable: 0 });

// ------------------------------------------------------------------ formatting

assert.equal(formatSpan(null), '–');
assert.equal(formatSpan(45 * 60000), '45 min');
assert.equal(formatSpan(4 * 3600000), '4.0 h');
assert.equal(formatSpan(36 * 3600000), '1.5 d');

// eslint-disable-next-line no-console
console.log('measures.check.mjs: all assertions passed');
```

- [ ] **Step 2: Run the check to verify it fails**

Run: `node src/Resources/app/administration/src/module/merchant-quote-agent/measures.check.mjs`
Expected: FAIL — cannot resolve `./measures.ts`.

- [ ] **Step 3: Write `measures.ts`**

Create it:

```ts
/**
 * The dashboard's four success measures, as pure functions over the folded
 * quote list, the raw passes, and the quote/order rows.
 *
 * Separate from decision.ts, which is already at the house file-length target:
 * this module imports the vocabulary from there and nothing flows back, so the
 * two stay one-directional.
 *
 * Every measure returns `null` rather than `0` when it has nothing to measure.
 * That distinction is the whole point on this page: a merchant without
 * `quote:read` must see a figure absent, not a confident zero, and so must a
 * period with no deals in it.
 */

import { answeredTheBuyer } from './decision';

interface Deal {
    quoteId: string;
    amountNet: number;
    submittedAt: string | null;
    confirmedAt: string | null;
    agentDiscount: number | null;
    baselineDiscount: number | null;
}

interface ValueRange {
    min: number;
    max: number;
}

/**
 * Share of quotes the agent handled without ever asking a human.
 *
 * The denominator is every quote serviced in the period, not only the
 * concluded ones. Restricting it to concluded negotiations would drop
 * unresolved escalations out of the denominator, so a shop with ten quotes
 * stuck in the review queue would report 100% auto-execution — a worse failure
 * than counting a still-open, never-escalated quote as auto-executed.
 */
export function autoExecutionRate(quotes: any[]): { rate: number | null; escalated: number; total: number } {
    const total = quotes.length;
    const escalated = quotes.filter((quote) => quote.escalated).length;

    return {
        rate: total > 0 ? ((total - escalated) / total) * 100 : null,
        escalated,
        total,
    };
}

/**
 * How long the deal desk took, over the escalations that have been resolved.
 *
 * Mean rather than median because the merchant-facing wording is "average",
 * and a median over the handful of escalations a period produces is not more
 * informative.
 *
 * `open` is reported alongside so a backlog reads as a backlog. It matters at
 * release: escalations raised before the resolved_at migration have no
 * resolution and would otherwise silently vanish from the measure.
 */
export function escalationResolution(
    passes: any[],
    slaHours: number | null,
): { meanMs: number | null; measured: number; open: number; withinSla: number | null } {
    const escalations = passes.filter((pass) => pass.outcome === 'escalated');
    const durations = escalations
        .map((pass) => span(pass.createdAt, pass.resolvedAt))
        .filter((ms): ms is number => ms !== null);
    const slaMs = typeof slaHours === 'number' && slaHours > 0 ? slaHours * 3600000 : null;

    return {
        meanMs: mean(durations),
        measured: durations.length,
        open: escalations.length - durations.length,
        withinSla: slaMs === null ? null : durations.filter((ms) => ms <= slaMs).length,
    };
}

/**
 * Splits the period's accepted quotes into the agent's and the baseline's,
 * with one definition of every figure so the two sides are comparable.
 *
 * `foldedByQuoteId` decides which side a quote is on: a quote with servicing
 * passes is the agent's, one without is the baseline — "quotes the agent never
 * touched", which is the only pre-agent comparison this shop can measure
 * without a segment model.
 */
export function splitDeals(
    quoteRows: any[],
    orderDates: Map<string, string>,
    foldedByQuoteId: Map<string, any>,
): { agent: Deal[]; baseline: Deal[] } {
    const agent: Deal[] = [];
    const baseline: Deal[] = [];

    (quoteRows ?? []).forEach((row) => {
        const folded = foldedByQuoteId.get(row.id) ?? null;

        const deal: Deal = {
            quoteId: row.id,
            amountNet: Number(row.amountNet ?? 0),
            // `requestedAt` is the true RFQ submission time and is the better
            // number, but it does not exist on SwagCommercial 7.12 — read
            // only, never filtered on, so its absence is `undefined` rather
            // than a DAL error.
            submittedAt: row.requestedAt ?? row.createdAt ?? null,
            confirmedAt: (row.orderId && orderDates.get(row.orderId)) || null,
            agentDiscount: folded === null ? null : agentDiscountPercent(folded),
            baselineDiscount: baselineDiscountPercent(row),
        };

        (folded === null ? baseline : agent).push(deal);
    });

    return { agent, baseline };
}

/**
 * What the agent gave away, from our own records, so it does not depend on
 * which SwagCommercial version wrote the quote.
 *
 * Null when the quote has no answered pass — a quote that escalated, was
 * answered by a human and then accepted has no agent-set price, and this
 * measure is the discount on AGENT-negotiated deals.
 *
 * Known limitation, inherited from QuoteBaselineLines rather than introduced
 * here: `netBefore` is the quote's stored total, unadjusted for a quantity
 * reduction or a line removal, so a buyer who halves a quantity shrinks the
 * total structurally and it reads as a concession.
 */
function agentDiscountPercent(quote: any): number | null {
    const original = Number(quote.netBefore ?? 0);
    const realized = quote.latestAnswered?.totalNetAfter;

    if (!(original > 0) || realized === null || realized === undefined) {
        return null;
    }

    return ((original - Number(realized)) / original) * 100;
}

/**
 * What a human gave away, from the quote's own discount totals.
 *
 * `totalLineItemDiscount` does not exist on SwagCommercial 7.12, so a merchant
 * there who negotiates by editing line prices rather than setting a quote
 * discount reads as 0%. That understates the baseline and so makes the agent
 * look worse, which is the safe direction, and the tile carries a footnote
 * saying the baseline reads quote-level discounts only.
 */
function baselineDiscountPercent(row: any): number | null {
    const given = Number(row.totalDiscount ?? 0) + Number(row.totalLineItemDiscount ?? 0);
    const original = Number(row.amountNet ?? 0) + given;

    return original > 0 ? (given / original) * 100 : null;
}

/**
 * The net-value band of the agent's deals, which is the "equivalent deal
 * sizes" control.
 *
 * Both "equivalent deal sizes" and "the same account segment" need a segment
 * this shop does not model, and net value is the one comparable dimension
 * available. Read off `amountNet` on both sides — never off the decision
 * record's pre-negotiation `netBefore`, which would bias the baseline upward
 * by exactly the discount being measured.
 */
export function valueRange(deals: Deal[]): ValueRange | null {
    const values = deals.map((deal) => deal.amountNet).filter((value) => Number.isFinite(value) && value > 0);

    if (values.length === 0) {
        return null;
    }

    return { min: Math.min(...values), max: Math.max(...values) };
}

/** No range means nothing comparable, not "compare against everything". */
export function withinRange(deals: Deal[], range: ValueRange | null): Deal[] {
    if (range === null) {
        return [];
    }

    return deals.filter((deal) => deal.amountNet >= range.min && deal.amountNet <= range.max);
}

/**
 * Discount granted on agent-negotiated deals against the same figure on deals
 * the agent never touched, in the same value band.
 *
 * Deliberately not called margin: margin needs COGS, which neither this plugin
 * nor a typical B2B catalog carries. This is the original price against the
 * price sold.
 */
export function priceRetention(
    agent: Deal[],
    baseline: Deal[],
): { agentDiscount: number | null; baselineDiscount: number | null; comparable: number } {
    const comparable = withinRange(baseline, valueRange(agent));

    return {
        agentDiscount: mean(agent.map((deal) => deal.agentDiscount)),
        baselineDiscount: mean(comparable.map((deal) => deal.baselineDiscount)),
        comparable: comparable.length,
    };
}

/** RFQ submission to confirmed order, both sides, same value band. */
export function dealCycleTime(
    agent: Deal[],
    baseline: Deal[],
): { agentMs: number | null; baselineMs: number | null; comparable: number } {
    const comparable = withinRange(baseline, valueRange(agent));

    return {
        agentMs: mean(agent.map(cycleMs)),
        baselineMs: mean(comparable.map(cycleMs)),
        comparable: comparable.length,
    };
}

function cycleMs(deal: Deal): number | null {
    return span(deal.submittedAt, deal.confirmedAt);
}

/**
 * Milliseconds between two instants, or null if either is missing or the pair
 * is backwards. A negative span is corrupt data, not a negative duration.
 */
function span(from: string | null | undefined, to: string | null | undefined): number | null {
    if (!from || !to) {
        return null;
    }

    const ms = Date.parse(to) - Date.parse(from);

    return Number.isFinite(ms) && ms >= 0 ? ms : null;
}

/** Null-tolerant mean. Null in, null out — never 0, which would read as measured. */
function mean(values: (number | null | undefined)[]): number | null {
    const numbers = values.filter((value): value is number => typeof value === 'number' && Number.isFinite(value));

    if (numbers.length === 0) {
        return null;
    }

    return numbers.reduce((sum, value) => sum + value, 0) / numbers.length;
}

/**
 * A duration at the scale a merchant reads it. `formatDuration` in decision.ts
 * is for a single pass and tops out at seconds; these spans are hours and
 * days.
 */
export function formatSpan(ms: number | null): string {
    if (ms === null || ms === undefined) {
        return '–';
    }

    if (ms < 3600000) {
        return `${Math.round(ms / 60000)} min`;
    }

    if (ms < 86400000) {
        return `${(ms / 3600000).toFixed(1)} h`;
    }

    return `${(ms / 86400000).toFixed(1)} d`;
}
```

`escalationResolution` returns exactly four keys — `meanMs`, `measured`, `open`, `withinSla` — because the check script asserts the whole object with `deepEqual`. Do not add a fifth (an earlier draft also returned `slaMs`); the template gets the SLA from the component's own `slaHours`.

- [ ] **Step 4: Run the check to verify it passes**

Run: `node src/Resources/app/administration/src/module/merchant-quote-agent/measures.check.mjs`
Expected: `measures.check.mjs: all assertions passed`, exit code 0.

If an assertion about `withinSla` on an empty pass list fails, note the expectation: with an SLA set and nothing measured, `withinSla` is `0` (zero escalations beat the SLA) while `meanMs` is `null` (nothing was measured). Those are different questions and the test asserts both.

- [ ] **Step 5: Commit**

```bash
git add src/Resources/app/administration/src/module/merchant-quote-agent/measures.ts \
        src/Resources/app/administration/src/module/merchant-quote-agent/measures.check.mjs
git commit -m "feat(admin): add the four dashboard success measures"
```

---

### Task 6: Wire the measures into the list page

**Files:**
- Modify: `src/Resources/app/administration/src/module/merchant-quote-agent/page/merchant-quote-agent-list/index.ts`

**Interfaces:**
- Consumes: everything Task 4 and Task 5 produce.
- Produces: computed properties `autoExecution`, `autoExecutionDelta`, `escalations`, `retention`, `cycleTime`, `dealsUnavailable`, and `data` keys `quoteRows`, `orderDates`, `slaHours`.

- [ ] **Step 1: Widen the pass read to two windows**

Replace the `PASS_LIMIT` docblock and constant, and add a second constant:

```ts
/**
 * ponytail: the page reads every pass in the period in one request and folds it
 * client-side, so the figures and the rows are one computation and cannot
 * disagree. The ceiling is PASS_LIMIT; past it the page says so rather than
 * quietly describing a subset. The upgrade path, if a shop ever services more
 * than this in 90 days, is a server-side latest-pass-per-quote read — DAL
 * `grouping` is not it: it returns the FIRST row per group regardless of
 * sorting and drops the total count.
 *
 * The read covers TWICE the selected range, because the auto-execution rate is
 * only meaningful as a trend and the previous equal-length window is the
 * comparison. The rows and every current-period figure filter to the recent
 * half. So the effective ceiling is half of PASS_LIMIT per window, which the
 * truncation banner already reports.
 */
const PASS_LIMIT = 500;

/**
 * Accepted quotes in the period, both agent-negotiated and not. Far smaller
 * than the pass read — most quotes never reach `accepted` — so this ceiling is
 * generous rather than tight.
 */
const QUOTE_LIMIT = 500;
const PAGE_SIZE = 25;
```

- [ ] **Step 2: Add the new data keys**

In `data()`, add to the returned object:

```ts
            quoteRows: null,
            orderDates: new Map(),
            slaHours: null,
```

`quoteRows` starts as `null`, not `[]`: null means "not read, or unreadable", and `[]` means "read, and there were none". The tiles show unavailable for the first and a real zero for the second.

- [ ] **Step 3: Split the window and rebuild the derived state**

Replace the `rangeFilter` computed and add the window helpers:

```ts
        /** The start of the period the page describes. */
        windowStart() {
            const from = new Date();
            from.setDate(from.getDate() - this.rangeDays);

            return from;
        },

        /**
         * The range the PASS query shares — twice the period, so the trend has
         * a previous window to compare against. Quote-side queries use
         * `rangeFilter`, which is the period itself.
         */
        trendRangeFilter() {
            const from = new Date(this.windowStart());
            from.setDate(from.getDate() - this.rangeDays);

            return Criteria.range('createdAt', { gte: from.toISOString() });
        },

        /** The range every quote-side query on this page shares. */
        rangeFilter() {
            return Criteria.range('createdAt', { gte: this.windowStart().toISOString() });
        },

        /** The passes inside the period the page describes. */
        currentPasses() {
            const start = this.windowStart().getTime();

            return this.passes.filter((pass) => Date.parse(pass.createdAt) >= start);
        },

        /** The equal-length window before it, for the trend only. */
        previousPasses() {
            const start = this.windowStart().getTime();

            return this.passes.filter((pass) => Date.parse(pass.createdAt) < start);
        },

        /** One row per quote, newest activity first. */
        quotes() {
            return foldToQuotes(this.currentPasses);
        },

        quotesByQuoteId() {
            return new Map(this.quotes.map((quote) => [quote.quoteId, quote]));
        },
```

- [ ] **Step 4: Replace the retired figure computeds with the four measures**

Delete these computed properties entirely — they are the figures being replaced: `partition`, `needsReview`, `orderPlaced`, `orderPlacedShare`, `needsReviewShare`, `restOfPartition`, `valueHandled`, `valueCurrency`, `granted`, `cap`, `capUsedShare`. Also delete the `averageOver` method, which only served `granted` and `cap`.

Add:

```ts
        autoExecution() {
            return autoExecutionRate(this.quotes);
        },

        /**
         * Movement against the previous equal-length window. Null when there
         * is nothing to compare against, so a first-week dashboard shows the
         * rate without inventing a trend for it.
         */
        autoExecutionDelta() {
            const previous = autoExecutionRate(foldToQuotes(this.previousPasses));

            if (previous.rate === null || this.autoExecution.rate === null) {
                return null;
            }

            return this.autoExecution.rate - previous.rate;
        },

        escalations() {
            return escalationResolution(this.currentPasses, this.slaHours);
        },

        /** The accepted quotes, split into the agent's and the untouched baseline. */
        deals() {
            return splitDeals(this.quoteRows ?? [], this.orderDates, this.quotesByQuoteId);
        },

        /** True when the quote read failed or never ran — tiles show unavailable, not zero. */
        dealsUnavailable() {
            return this.quoteRows === null;
        },

        retention() {
            return priceRetention(this.deals.agent, this.deals.baseline);
        },

        cycleTime() {
            return dealCycleTime(this.deals.agent, this.deals.baseline);
        },
```

Extend the imports at the top of the file:

```ts
import {
    answeredTheBuyer,
    askSummary,
    dispositionVariant,
    escalationLabel,
    foldToQuotes,
    formatCurrency,
    formatDate,
    formatDateShort,
    formatPercent,
    outcomeLabel,
    outcomeVariant,
} from '../../decision';
import {
    autoExecutionRate,
    dealCycleTime,
    escalationResolution,
    formatSpan,
    priceRetention,
    splitDeals,
} from '../../measures';
```

`ANSWERED_OUTCOMES` and `answeredTheBuyer` — check whether each is still used after the deletions and drop any import that is not. `formatSpan` goes in the `methods` block beside `formatCurrency` so the template can call it.

- [ ] **Step 5: Default the filter and add the new class to its options**

```ts
            dispositionFilter: 'needsReview',
```

and in `dispositionFilterOptions`, extend the class list so `closedNoDeal` is offered:

```ts
                ...['orderPlaced', 'needsReview', 'answered', 'awaitingBuyer', 'closedNoDeal', 'noAction'].map((key) => ({
```

- [ ] **Step 6: Replace `loadIntake` with the two quote-side reads**

Delete `loadIntake()` and the `intake` data key. Add:

```ts
        async load() {
            this.isLoading = true;

            try {
                // The SLA and the passes are independent; the order dates need
                // the quote rows first, so those two are sequential.
                await Promise.all([
                    this.loadPasses(),
                    this.loadSla(),
                    this.loadQuotes().then(() => this.loadOrderDates()),
                ]);
            } finally {
                this.isLoading = false;
            }
        },
```

and, in `loadPasses`, swap `this.rangeFilter` for `this.trendRangeFilter`. Then:

```ts
        /**
         * The period's accepted quotes, agent-negotiated or not — the split is
         * by whether decision rows exist for them, which the page already
         * knows. Only accepted quotes matter: an unbought discount is not
         * realized and an unconfirmed deal has no cycle time.
         *
         * `requestedAt` and `totalLineItemDiscount` do not exist on
         * SwagCommercial 7.12. They are READ here and never filtered or sorted
         * on, so on 7.12 they arrive undefined and measures.ts falls back,
         * rather than the whole query failing.
         */
        async loadQuotes() {
            try {
                const quoteRepository = this.repositoryFactory.create('quote');

                const criteria = new Criteria(1, QUOTE_LIMIT);
                criteria.addFilter(this.rangeFilter);
                criteria.addFilter(Criteria.equals('stateMachineState.technicalName', ORDER_PLACED_TERMINAL_STATE));
                criteria.addSorting(Criteria.sort('createdAt', 'DESC'));

                const result = await quoteRepository.search(criteria, Shopware.Context.api);

                this.quoteRows = Array.from(result);
            } catch (error) {
                // Nulled rather than zeroed: a viewer without `quote:read`
                // should see the figures absent, not see a 0% discount and a
                // zero-day cycle.
                this.quoteRows = null;
                // eslint-disable-next-line no-console
                console.error('merchant-quote-agent: quote figures unavailable', error);
            }
        },

        /**
         * When each accepted quote's order was placed, which is the confirmed
         * end of the deal cycle.
         *
         * A second read rather than an association: `quote.order` is declared
         * WITHOUT ApiAware on both 7.12 and 7.13, so the admin API cannot
         * traverse it. `quote.orderId` is ApiAware, so the ids come from the
         * quote read and the dates from the core order repository.
         */
        async loadOrderDates() {
            const ids = (this.quoteRows ?? []).map((row) => row.orderId).filter(Boolean);

            if (ids.length === 0) {
                this.orderDates = new Map();

                return;
            }

            try {
                const orderRepository = this.repositoryFactory.create('order');

                const criteria = new Criteria(1, ids.length);
                criteria.setIds(ids);

                const result = await orderRepository.search(criteria, Shopware.Context.api);

                this.orderDates = new Map(
                    Array.from(result).map((order) => [order.id, order.orderDateTime]),
                );
            } catch (error) {
                // Emptied, not nulled: the quotes were readable, so every
                // other measure still stands. Deal cycle time alone goes
                // unavailable, because no deal has a confirmed date.
                this.orderDates = new Map();
                // eslint-disable-next-line no-console
                console.error('merchant-quote-agent: order dates unavailable', error);
            }
        },

        /**
         * The escalation SLA, read straight from system config at global
         * scope. It benchmarks a figure on this page and steers nothing in the
         * pipeline, which is why it is not part of QuoteAgentSettings.
         */
        async loadSla() {
            try {
                const values = await Shopware.Service('systemConfigApiService')
                    .getValues('MerchantQuoteAgentPlugin.config');
                const value = values?.['MerchantQuoteAgentPlugin.config.escalationSlaHours'];

                this.slaHours = typeof value === 'number' && value > 0 ? value : null;
            } catch (error) {
                // No SLA means the tile reports the measured time with no
                // verdict, which is the same as a shop that left it blank.
                this.slaHours = null;
                // eslint-disable-next-line no-console
                console.error('merchant-quote-agent: escalation SLA unavailable', error);
            }
        },
```

Import `ORDER_PLACED_TERMINAL_STATE` from `'../../decision'` — it is the string `'accepted'`, and using the exported constant keeps the quote filter and the disposition table on one value.

- [ ] **Step 7: Verify the module still parses and the checks still pass**

Run:

```bash
node src/Resources/app/administration/src/module/merchant-quote-agent/decision.check.mjs
node src/Resources/app/administration/src/module/merchant-quote-agent/measures.check.mjs
node --input-type=module -e "import('./src/Resources/app/administration/src/module/merchant-quote-agent/measures.ts').then(() => console.log('measures.ts parses'))"
```

Expected: both checks pass. The page's `index.ts` cannot be imported standalone (it calls `Shopware.Component.register`), so it is verified in the browser in Task 8 — that is the only way, and Task 8 does it.

- [ ] **Step 8: Commit**

```bash
git add src/Resources/app/administration/src/module/merchant-quote-agent/page/merchant-quote-agent-list/index.ts
git commit -m "feat(admin): compute the success measures on the dashboard"
```

---

### Task 7: The four tiles and the snippets

**Files:**
- Modify: `.../page/merchant-quote-agent-list/merchant-quote-agent-list.html.twig`
- Modify: `src/Resources/app/administration/src/module/merchant-quote-agent/merchant-quote-agent.scss`
- Modify: `.../snippet/en.json` and `.../snippet/de.json`

**Interfaces:**
- Consumes: the computed properties from Task 6, and `formatSpan` / `formatPercent` as methods.
- Produces: no JS API. The card keeps its `{% block merchant_quote_agent_list_figures %}` name so any extension overriding it still resolves.

- [ ] **Step 1: Add the snippets**

In `snippet/en.json`, replace the `figures` object's retired keys. **Remove**: `received`, `expiredUnanswered`, `valueHandled`, `mixedCurrencies`, `granted`, `needsReview`, `ofServiced`, `ofCap`, `orderPlaced`. **Keep** `title`. Add:

```json
        "autoExecution": "Auto-execution rate",
        "autoExecutionAside": "{escalated} of {total} needed a human",
        "trendUp": "▲ {delta} vs the previous {days} days",
        "trendDown": "▼ {delta} vs the previous {days} days",
        "trendFlat": "unchanged vs the previous {days} days",
        "resolution": "Escalation resolution time",
        "withinSla": "{within} of {measured} within the {sla} SLA",
        "noSla": "{measured} resolved, no SLA set",
        "openEscalations": "{open} still open",
        "retention": "Discount granted",
        "retentionBaseline": "vs {baseline} on deals the agent never touched",
        "retentionFootnote": "The comparison reads quote-level discounts only.",
        "cycleTime": "Deal cycle time",
        "cycleBaseline": "vs {baseline} for comparable deal sizes",
        "noBaseline": "no comparable deals in this period",
        "unavailable": "Unavailable",
        "unavailableQuotes": "Needs permission to read quotes",
        "unavailableOrders": "Needs permission to read orders"
```

Add the same keys to `de.json` with German wording. Keep the `▲`/`▼` glyphs identical in both.

- [ ] **Step 2: Replace the figures card**

In the template, replace everything between `{% block merchant_quote_agent_list_figures %}` and its matching `{% endblock %}` — that is both the `merchant_quote_agent_list_disposition` block and the `merchant_quote_agent_list_intake` block, plus the `<hr class="mqa-rule" />` between them — with:

```twig
    {% block merchant_quote_agent_list_figures %}
    <sw-card :title="$tc('merchant-quote-agent.figures.title')" :is-loading="isLoading">
        {# Four measures, each with the comparison that makes it mean
           something: a rate needs a trend, a duration needs an SLA, and a
           discount or a cycle time needs the deals the agent never touched.
           A figure with no comparison available says so rather than standing
           alone as if it were self-evident. #}
        <div class="mqa-figures">
            {% block merchant_quote_agent_list_auto_execution %}
            <div class="mqa-figure mqa-figure--meter">
                <span class="mqa-figure__label">{{ $tc('merchant-quote-agent.figures.autoExecution') }}</span>
                <span class="mqa-figure__value">{{ formatPercent(autoExecution.rate) }}</span>
                <span class="mqa-figure__aside">
                    {{ $t('merchant-quote-agent.figures.autoExecutionAside', {
                        escalated: autoExecution.escalated,
                        total: autoExecution.total,
                    }) }}
                </span>
                {# The share is also drawn, because a rate reads faster as a
                   bar. The count above it is always present, so nothing is
                   encoded by colour alone. #}
                <div v-if="autoExecution.rate !== null" class="mqa-meter">
                    <div class="mqa-meter__fill" :style="{ width: autoExecution.rate + '%' }"></div>
                </div>
                <span v-if="autoExecutionDelta !== null" class="mqa-figure__aside mqa-trend">
                    <template v-if="Math.abs(autoExecutionDelta) < 0.05">
                        {{ $t('merchant-quote-agent.figures.trendFlat', { days: rangeDays }) }}
                    </template>
                    <template v-else-if="autoExecutionDelta > 0">
                        {{ $t('merchant-quote-agent.figures.trendUp', {
                            delta: formatPercent(autoExecutionDelta),
                            days: rangeDays,
                        }) }}
                    </template>
                    <template v-else>
                        {{ $t('merchant-quote-agent.figures.trendDown', {
                            delta: formatPercent(-autoExecutionDelta),
                            days: rangeDays,
                        }) }}
                    </template>
                </span>
            </div>
            {% endblock %}

            {% block merchant_quote_agent_list_resolution %}
            <div class="mqa-figure">
                <span class="mqa-figure__label">{{ $tc('merchant-quote-agent.figures.resolution') }}</span>
                <span class="mqa-figure__value">{{ formatSpan(escalations.meanMs) }}</span>
                <span v-if="escalations.withinSla !== null" class="mqa-figure__aside">
                    {{ $t('merchant-quote-agent.figures.withinSla', {
                        within: escalations.withinSla,
                        measured: escalations.measured,
                        sla: formatSpan(slaHours * 3600000),
                    }) }}
                </span>
                <span v-else class="mqa-figure__aside">
                    {{ $t('merchant-quote-agent.figures.noSla', { measured: escalations.measured }) }}
                </span>
                {# Reported beside the average, not folded into it. At release
                   every escalation raised before the resolved_at migration is
                   open, and a backlog has to read as a backlog rather than
                   quietly vanishing from the average. #}
                <span v-if="escalations.open > 0" class="mqa-figure__aside">
                    {{ $t('merchant-quote-agent.figures.openEscalations', { open: escalations.open }) }}
                </span>
            </div>
            {% endblock %}

            {% block merchant_quote_agent_list_retention %}
            <div class="mqa-figure">
                <span class="mqa-figure__label">{{ $tc('merchant-quote-agent.figures.retention') }}</span>
                <span class="mqa-figure__value">
                    <template v-if="dealsUnavailable">{{ $tc('merchant-quote-agent.figures.unavailable') }}</template>
                    <template v-else>{{ formatPercent(retention.agentDiscount) }}</template>
                </span>
                <span v-if="dealsUnavailable" class="mqa-figure__aside">
                    {{ $tc('merchant-quote-agent.figures.unavailableQuotes') }}
                </span>
                <span v-else-if="retention.baselineDiscount !== null" class="mqa-figure__aside">
                    {{ $t('merchant-quote-agent.figures.retentionBaseline', {
                        baseline: formatPercent(retention.baselineDiscount),
                    }) }}
                </span>
                <span v-else class="mqa-figure__aside">
                    {{ $tc('merchant-quote-agent.figures.noBaseline') }}
                </span>
                <span v-if="!dealsUnavailable && retention.baselineDiscount !== null" class="mqa-figure__aside">
                    {{ $tc('merchant-quote-agent.figures.retentionFootnote') }}
                </span>
            </div>
            {% endblock %}

            {% block merchant_quote_agent_list_cycle_time %}
            <div class="mqa-figure">
                <span class="mqa-figure__label">{{ $tc('merchant-quote-agent.figures.cycleTime') }}</span>
                <span class="mqa-figure__value">
                    <template v-if="dealsUnavailable">{{ $tc('merchant-quote-agent.figures.unavailable') }}</template>
                    <template v-else>{{ formatSpan(cycleTime.agentMs) }}</template>
                </span>
                <span v-if="dealsUnavailable" class="mqa-figure__aside">
                    {{ $tc('merchant-quote-agent.figures.unavailableQuotes') }}
                </span>
                <span v-else-if="cycleTime.baselineMs !== null" class="mqa-figure__aside">
                    {{ $t('merchant-quote-agent.figures.cycleBaseline', {
                        baseline: formatSpan(cycleTime.baselineMs),
                    }) }}
                </span>
                <span v-else class="mqa-figure__aside">
                    {{ $tc('merchant-quote-agent.figures.noBaseline') }}
                </span>
            </div>
            {% endblock %}
        </div>
    </sw-card>
    {% endblock %}
```

- [ ] **Step 3: Trim the stylesheet**

In `merchant-quote-agent.scss`:

- **Delete** every `.mqa-standing*` rule (lines ~76–150 — `.mqa-standing`, `__lede`, `__label`, `__value`, `__of`, `--won`, `__bar`, `__fill`, `__rest`, `__rest dt`, `__rest dd`). They were list-only; grep confirms the detail and access pages do not use them.
- **Delete** the `.mqa-rule` rule — the `<hr>` that used it is gone.
- **Keep** `.mqa-figures`, `.mqa-figure`, `.mqa-figure__label`, `.mqa-figure__value`, `.mqa-figure__aside`, `.mqa-figure--meter`, `.mqa-meter`, `.mqa-meter__fill`. The detail page uses the `.mqa-figure*` family, and the new tiles reuse all of it.
- **Add** one rule, for the trend line:

```scss
/* The trend sits under the meter, so it needs to clear it. */
.mqa-trend {
    margin-top: 6px;
}
```

Before deleting any class, confirm it is unused:

```bash
grep -rn "mqa-standing\|mqa-rule" src/Resources/app/administration/src/module/merchant-quote-agent/
```

Expected after the template edit: only the `.scss` definitions match. If a page still references one, do not delete it.

- [ ] **Step 4: Check for leftovers**

```bash
grep -rn "intake\|valueHandled\|capUsedShare\|restOfPartition\|orderPlacedShare\|needsReviewShare\|averageOver" \
  src/Resources/app/administration/src/module/merchant-quote-agent/
```

Expected: no matches. Any hit is a retired figure still referenced somewhere — remove it.

Then re-run both check scripts:

```bash
node src/Resources/app/administration/src/module/merchant-quote-agent/decision.check.mjs
node src/Resources/app/administration/src/module/merchant-quote-agent/measures.check.mjs
```

Expected: both pass.

- [ ] **Step 5: Commit**

```bash
git add src/Resources/app/administration/src/module/merchant-quote-agent/page/merchant-quote-agent-list/merchant-quote-agent-list.html.twig \
        src/Resources/app/administration/src/module/merchant-quote-agent/merchant-quote-agent.scss \
        src/Resources/app/administration/src/module/merchant-quote-agent/snippet/en.json \
        src/Resources/app/administration/src/module/merchant-quote-agent/snippet/de.json
git commit -m "feat(admin): render the four success measures and default to needs review"
```

---

### Task 8: Verify on both SwagCommercial lanes

A passing test on one shop says nothing about the other: they sit deliberately on opposite sides of the released/trunk split. This task is the acceptance gate and it is not optional.

**Files:**
- Modify: `README.md` (the dashboard section)

**Interfaces:**
- Consumes: everything above.
- Produces: nothing in code.

- [ ] **Step 1: Run the whole local gate**

```bash
composer format:check && composer lint && composer typecheck && composer quality:filesize
vendor/bin/phpunit
node src/Resources/app/administration/src/module/merchant-quote-agent/decision.check.mjs
node src/Resources/app/administration/src/module/merchant-quote-agent/measures.check.mjs
```

Expected: all green. Report any failure verbatim rather than working around it.

- [ ] **Step 2: Run the integration suite**

```bash
composer test:integration
```

Expected: PASS. `PluginConfigTest` may fail on a shop with live configuration rather than install-time defaults — that is a known property of the test shop, not a regression from this work. Confirm any failure predates this branch with `git stash list`-free verification: check out `main` in a scratch worktree and run the same filter there before calling it pre-existing.

- [ ] **Step 3: Verify on the 7.13 lane**

Deploy the branch to the `agenticquote` shop (`agenticquote-shoelscher.eu-core-1.shopdev.de`, docroot `~/files/agenticquote`, SwagCommercial 7.13.1), run the plugin's migrations, and open the dashboard.

Check, and report each one explicitly:

1. The grid opens filtered to **Needs review**.
2. The four tiles render, none showing a snippet path (a raw `merchant-quote-agent.figures.…` string means a missing key).
3. Auto-execution rate's count matches the number of escalated quotes visible under the *Needs review* filter plus any already resolved.
4. Escalation resolution time shows `n still open` for the pre-existing escalations, because none of them has a `resolved_at`.
5. Set the SLA to a value in the plugin config, reload, and confirm the tile switches from "no SLA set" to "n of m within the … SLA".
6. Deal cycle time is non-empty — this shop has accepted quotes with orders.
7. Batch every SSH operation into as few connections as possible.

Then exercise the resolution path end to end: take a quote the agent escalated, act on it as the merchant in SwagCommercial's quote admin (send a revised offer), and confirm

```sql
SELECT quote_number, outcome, resolved_at, resolved_state
FROM merchant_quote_agent_decision
WHERE quote_id = UNHEX('…');
```

now shows `resolved_at` and `resolved_state`, and the dashboard row has moved out of *Needs review* into *Question asked*. Then accept the quote as the customer and confirm the row becomes *Order placed*.

Finally, confirm the drain fix on a non-accepting terminal state: decline an escalated quote and check its row reads **Closed, no deal** rather than staying in *Needs review*.

- [ ] **Step 4: Verify on the 7.12 lane**

Deploy to `b2bseller` (`ssh shoelscher@b2bseller-shoelscher.eu-core-1.shopdev.de`, docroot `~/files/b2bseller`, SwagCommercial 7.12.0).

**This host IP-bans on frequent SSH connections.** Batch every remote operation into a single compound command. No loops, no polling, no connection per file.

Check:

1. The dashboard loads at all — no console error from a missing field. This is the whole point of the read-only `??` fallbacks.
2. Deal cycle time renders, using `quote.createdAt` because `requestedAt` does not exist here. Compare the figure against the quote's own created date to confirm the fallback took.
3. Price retention's baseline renders from `totalDiscount` alone, and the footnote about quote-level discounts is visible.
4. The `resolved_at` column exists and the subscriber stamps it — the escalation path does not depend on any 7.13 field.

- [ ] **Step 5: Update the README**

Find the section describing the dashboard and rewrite it for the four measures. State plainly:

- what each measure is and what it compares against
- that price retention is not gross margin, and why (no COGS in the plugin or the catalog)
- that the baseline is "quotes the agent never touched", matched on net value
- that escalation resolution time only covers escalations resolved after the `resolved_at` migration, and that a transition by anyone closes an escalation because the core event carries no author
- that on SwagCommercial 7.12 the price-retention baseline sees quote-level discounts only

- [ ] **Step 6: Commit and open the PR**

```bash
git add README.md
git commit -m "docs: describe the dashboard's success measures"
git push -u origin spec/quote-agent-dashboard-measures
```

Open the PR against `main`, and put the two lanes' verification results in the body — including the exact quote numbers used for the resolution and decline walkthroughs, so a reviewer can re-check them.

---

## Self-Review

**Spec coverage.** Every section maps to a task: §1 (client-side) → Tasks 5–7; §2 (resolution recording) → Tasks 1–2; §3 (SLA field) → Task 3; §4.1–4.5 (the measures) → Task 5, wired in Task 6; §5 (loading) → Task 6 Step 6; §6 (disposition fix) → Task 4; §7 (the grid) → Task 6 Step 5 and Task 7. The spec's Testing section maps to the check-script additions in Tasks 4–5, the PHP tests in Tasks 1–3, and the two-lane verification in Task 8.

**Deliberate deviation from the spec.** The spec puts the measures in `decision.ts`; this plan gives them `measures.ts` instead, because `decision.ts` is already 428 lines against a ~400-line house target. Rationale is recorded in Task 5.

**Arithmetic checked by hand.** The fixtures in Task 5's check script were worked through before being written down: auto-execution 3-of-4 → 75%; resolution mean of 4h and 8h → 6h, one of two inside a 6h SLA; price retention mean of 10% and 4% → 7% against a 20% baseline; cycle time mean of 24h, 24h and 12h → 20h against 72h. The value-range control is what makes `priceRetention(agent, baseline)` report a null baseline while `priceRetention(wideAgent, baseline)` reports 20% — the 800-net baseline quote sits below the two-deal agent range's 900 floor and only enters once a 700-net agent deal widens it. If an executor changes a fixture, these five figures all move.

**Type consistency.** `escalationResolution` returns four keys everywhere it appears (Task 5's implementation, its check script, and the template in Task 7). `Deal` has the same six fields in `splitDeals`, `valueRange`, `withinRange`, `priceRetention` and `dealCycleTime`. `disposition()`'s third parameter is `resolvedAt` in Task 4's signature, its call sites inside `foldToQuotes`, and its assertions.
