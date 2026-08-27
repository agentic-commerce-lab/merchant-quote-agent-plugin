# Quote Servicing Loop Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the webhook path with two in-process listeners, one async message, a per-quote lock, and an idempotency marker, so duplicate or concurrent triggers for one quote produce exactly one servicing pass.

**Architecture:** Two core event listeners normalise quote state entries and quote-comment inserts into one `ServiceQuoteMessage`, routed to Shopware's `async` transport. A message handler takes a per-quote `symfony/lock`, re-reads the quote, and compares a fingerprint of *what was serviced* (state, authored-comment count, newest authored `createdAt`) against a marker persisted on the quote's `customFields`. Equal means a duplicate trigger and it returns; different means real work and it hands the snapshot to `QuoteServicingPipelineInterface`, which issue #18 implements. The agent's own writes carry a `Context` state that both listeners skip.

**Tech Stack:** PHP 8.3, Shopware 6.7 (`dev-trunk`), SwagCommercial 7.13.1, Symfony 7.4 (Messenger, Lock, EventDispatcher), PHPUnit 11, Mago (format/lint/analyze).

**Spec:** `docs/superpowers/specs/2026-08-27-quote-servicing-loop-design.md`

## Global Constraints

- `declare(strict_types=1)` in every PHP file.
- Mago analyze runs at full strictness. No `mixed`, no unsafe casts. Narrow with `\is_string()` / `\is_int()` before use.
- Thresholds: cyclomatic complexity 10, nesting depth 4, parameters 5, ~400 lines/file.
- Target PHP 8.3 (`mago.toml` `php-version`, `composer.json` `config.platform.php` = `8.3.32`).
- PSR-3 logger only. Never `echo`/`var_dump`/`print_r`/`dd` in `src/`.
- Throw `Throwable` subclasses only. Preserve `$previous` when wrapping. No `return`/`throw` from `finally`.
- **Never reference a SwagCommercial class with `::class`.** Class-name string literals only, and only inside `src/Bridge/Commercial/`. ADR 0001. Nothing in this plan needs a SwagCommercial type.
- `#[\Override]` on every interface/parent method implementation — house style, see `SwagCommercialQuoteGateway`.
- Branch: `feat/4-quote-servicing-loop`. Commit after every task.
- Integration tests run only inside the shop container: `composer run test:integration`. Never wired into CI.
- Marker keys, fixed: `merchant_quote_agent_serviced` (fingerprint), `merchant_quote_agent_attempts` (crash counter). `MAX_ATTEMPTS = 4`. Lock TTL `300.0` seconds. Lock key prefix `merchant-quote-agent.quote.`.
- Trigger states, fixed: `open` and `change_requested`. Nothing else.
- Context state constant, fixed: `merchant-quote-agent`.

---

### Task 1: Probe whether Symfony tolerates a null-returning factory

`QuoteGatewayInterface` is registered with `factory([QuoteGatewayFactory, 'create'])`, which returns `null` on a shop without a SwagCommercial licence. **Nothing in this codebase has ever resolved that service** — the handler in Task 8 is its first consumer. If Symfony's compiled container rejects a null-returning factory, the handler's `?QuoteGatewayInterface` design cannot work and two files change shape. Find out before building on it.

This task writes no production code and leaves no artifact in the repo.

**Files:**
- None. The probe runs inside the container via `docker exec` and is discarded.
- Modify (only if the probe fails): `docs/superpowers/specs/2026-08-27-quote-servicing-loop-design.md`

**Interfaces:**
- Consumes: nothing.
- Produces: a decision recorded in the task's commit message — either "null-returning factory works, Task 8 proceeds as specified" or "does not work, Task 8 uses `QuoteGatewayLocator`".

- [ ] **Step 1: Run the probe against the container's own Symfony**

This reproduces the exact pattern in `src/Resources/config/services.php` — a service whose factory returns null, injected through an `ignoreOnInvalid()` reference into a nullable constructor parameter — in a throwaway container. Run it verbatim:

```bash
docker exec merchant-quote-shop php8.3 -r '
require "/var/www/html/vendor/autoload.php";

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ContainerInterface;

interface Gw {}
class GwFactory { public function create(): ?Gw { return null; } }
class Consumer {
    public function __construct(public readonly ?Gw $gw = null) {}
}

$c = new ContainerBuilder();
$c->register("gw_factory", GwFactory::class);
$c->register("gw", Gw::class)->setFactory([new Reference("gw_factory"), "create"])->setPublic(true);
$c->register("consumer", Consumer::class)
  ->setArguments([new Reference("gw", ContainerInterface::IGNORE_ON_INVALID_REFERENCE)])
  ->setPublic(true);

try {
    $c->compile();
    $consumer = $c->get("consumer");
    printf("RESULT: compiled and resolved. gateway is %s%s",
        $consumer->gw === null ? "NULL" : "an object", PHP_EOL);
} catch (\Throwable $e) {
    printf("RESULT: FAILED with %s: %s%s", $e::class, $e->getMessage(), PHP_EOL);
}
'
```

Expected if the design holds: `RESULT: compiled and resolved. gateway is NULL`

- [ ] **Step 2: Confirm the same holds in the real, compiled container**

The probe above uses a runtime `ContainerBuilder`; the shop uses a dumped PHP container, which generates different code. Confirm the real one resolves the real service:

```bash
docker exec merchant-quote-shop php8.3 bin/console debug:container \
  'MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface' 2>&1 | head -20
```

Expected: the service is listed with `Class: MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface` and a factory. The licence is ON in this shop, so it resolves to a real gateway — this step proves the registration compiles, and Step 1 proves the null case.

- [ ] **Step 3: Branch on the result**

**If Step 1 printed `gateway is NULL`:** the design holds. Record it and move on — no code changes. Skip to Step 4.

**If Step 1 printed `RESULT: FAILED`:** apply the fallback before continuing. Add this to the spec's "Risks and what verifies them" row for the null factory, replacing its "First task in the plan" sentence:

```markdown
Probed 2026-08-27: Symfony **rejects** a null-returning factory. Resolved by
`QuoteGatewayLocator`, a non-nullable service the handler asks at call time:

    final readonly class QuoteGatewayLocator
    {
        public function __construct(private QuoteGatewayFactory $factory) {}

        public function gateway(): ?QuoteGatewayInterface
        {
            return $this->factory->create();
        }
    }

`services.php` drops the `QuoteGatewayInterface` factory registration and
registers `QuoteGatewayLocator` instead. Task 8's handler takes
`QuoteGatewayLocator` (non-nullable) and calls `gateway()` at the top of
`__invoke()`, keeping the same null branch and the same log line.
```

Then adjust Task 8 and Task 10 accordingly as you implement them: the handler's constructor takes `QuoteGatewayLocator $gateways` in place of `?QuoteGatewayInterface $gateway`, and `__invoke()` opens with `$gateway = $this->gateways->gateway();`. Everything downstream of that local variable is unchanged.

- [ ] **Step 4: Commit the finding**

Nothing to commit if the probe passed and the spec is unchanged — record the result in the next task's commit body instead. If the spec changed:

```bash
git add docs/superpowers/specs/2026-08-27-quote-servicing-loop-design.md
git commit -m "docs: record that Symfony rejects a null-returning factory

Probed in the shop container before building on it. QuoteGatewayInterface's
factory returns null on an unlicensed shop and the servicing handler is its
first consumer, so the nullable-service design needed verifying rather than
assuming. Replaced with QuoteGatewayLocator, asked at call time."
```

---

### Task 2: Declare the dependencies

`symfony/messenger`, `symfony/lock`, `symfony/event-dispatcher` and `psr/log` all resolve today through `shopware/core`, but using them undeclared is a shadow dependency and `composer run quality:depcheck` is a blocking gate. Declare them before the first file imports them.

**Files:**
- Modify: `composer.json:6-14` (the `require` block)

**Interfaces:**
- Consumes: nothing.
- Produces: `Symfony\Component\Messenger\*`, `Symfony\Component\Lock\*`, `Symfony\Component\EventDispatcher\*` and `Psr\Log\LoggerInterface` are declared and legal to import in `src/`.

- [ ] **Step 1: Add the four packages**

In `composer.json`, the `require` block becomes (keys stay alphabetically sorted — `composer.json` sets `"sort-packages": true`):

```json
    "require": {
        "php": "^8.3",
        "cuyz/valinor": "^2.6",
        "psr/log": "^3.0",
        "shopware/core": "~6.7.0",
        "symfony/dependency-injection": "^7.4",
        "symfony/event-dispatcher": "^7.4",
        "symfony/lock": "^7.4",
        "symfony/messenger": "^7.4",
        "symfony/validator": "^7.4",
        "ucp-php-sdk/core": ">=0.0.5 <0.1.0"
    },
```

The constraints match what `shopware/core` already resolves (`symfony/lock ~7.4.0`, `symfony/messenger ~7.4.12`), so no package version changes.

- [ ] **Step 2: Update the lock file without changing resolved versions**

Run: `composer update --lock --no-scripts`

Expected: `composer.lock` content hash updates; no package is added, removed, or version-changed. Verify with `git diff --stat composer.lock` — a handful of lines, not hundreds.

- [ ] **Step 3: Verify the dependency gate is green**

Run: `composer run quality:depcheck`

Expected: no `unused dependency` and no `shadow dependency` findings. The four new packages are not yet imported anywhere, and this analyser **reports unused declared dependencies as errors** — so if it complains that the four are unused, that is expected at this point and is resolved by Tasks 5–9 importing them. Note the finding and continue; re-run this command at the end of Task 10, where it must be clean.

- [ ] **Step 4: Commit**

```bash
git add composer.json composer.lock
git commit -m "build: declare messenger, lock, event-dispatcher and psr/log

All four resolve through shopware/core today, but using them undeclared is a
shadow dependency and quality:depcheck is a blocking gate. Constraints match
what core already resolves, so no package version moves."
```

---

### Task 3: Teach the read model what servicing needs

The fingerprint needs to know whether a comment has an author, and #18 needs the sales channel. Both are read-model additions to the bridge, with no behaviour change.

**Files:**
- Modify: `src/Bridge/Data/QuoteComment.php`
- Modify: `src/Bridge/QuoteCommentMapper.php:28-37`
- Modify: `src/Bridge/Data/QuoteIdentity.php`
- Modify: `src/Bridge/QuoteSnapshotReader.php:74-85` (`readIdentity`)
- Test: `tests/Unit/Bridge/Data/QuoteCommentTest.php` (create)
- Test: `tests/Integration/FetchSnapshotTest.php` (add one test method)

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `QuoteComment::__construct(string $comment, ?string $lineItemId = null, ?string $createdById = null, ?string $customerId = null, ?\DateTimeImmutable $createdAt = null, ?string $employeeId = null)`
  - `QuoteComment::isAuthored(): bool`
  - `QuoteIdentity::__construct(string $quoteId, string $quoteNumber, string $currencyIso, string $salesChannelId = '')` — `$salesChannelId` readable as `$snapshot->identity->salesChannelId`.

- [ ] **Step 1: Write the failing unit test**

Create `tests/Unit/Bridge/Data/QuoteCommentTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge\Data;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteComment;
use PHPUnit\Framework\TestCase;

/**
 * `isAuthored()` is load-bearing for the servicing fingerprint: an agent
 * comment is author-less on all three fields (#3, pinned by AddCommentTest),
 * so "authored" is what separates a buyer's ask from our own reply.
 */
final class QuoteCommentTest extends TestCase
{
    public function testACommentWithNoAuthorFieldsIsNotAuthored(): void
    {
        $comment = new QuoteComment('agent reply');

        self::assertFalse($comment->isAuthored());
    }

    public function testACustomerCommentIsAuthored(): void
    {
        $comment = new QuoteComment('buyer ask', customerId: 'c1');

        self::assertTrue($comment->isAuthored());
    }

    public function testAnAdminAuthoredCommentIsAuthored(): void
    {
        $comment = new QuoteComment('staff note', createdById: 'u1');

        self::assertTrue($comment->isAuthored());
    }

    public function testAnEmployeeCommentIsAuthored(): void
    {
        $comment = new QuoteComment('employee ask', employeeId: 'e1');

        self::assertTrue($comment->isAuthored());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer run test -- --filter QuoteCommentTest`

Expected: FAIL — `Unknown named parameter $employeeId` on the fourth test, and `Call to undefined method ...::isAuthored()` on all four.

- [ ] **Step 3: Add `employeeId` and `isAuthored()` to the DTO**

`src/Bridge/Data/QuoteComment.php` becomes:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data;

/**
 * `createdById` / `customerId` / `employeeId` are how a reader tells a buyer's
 * comment from the agent's own: #3 measured all three as null on an agent
 * comment, and AddCommentTest pins that. `isAuthored()` is the servicing
 * fingerprint's discriminator — see Servicing\ServicingFingerprint.
 */
final readonly class QuoteComment
{
    public function __construct(
        public string $comment,
        public ?string $lineItemId = null,
        public ?string $createdById = null,
        public ?string $customerId = null,
        public ?\DateTimeImmutable $createdAt = null,
        public ?string $employeeId = null,
    ) {}

    public function isAuthored(): bool
    {
        return $this->createdById !== null || $this->customerId !== null || $this->employeeId !== null;
    }
}
```

`$employeeId` goes **last**, after `$createdAt`, so no existing positional call site shifts. `QuoteCommentMapper` is the only constructor caller and it uses named arguments anyway.

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer run test -- --filter QuoteCommentTest`

Expected: PASS, 4 tests.

- [ ] **Step 5: Map `employeeId` in the mapper**

In `src/Bridge/QuoteCommentMapper.php`, inside the `foreach`, add the field to the constructor call:

```php
            $createdAt = $comment->get('createdAt');
            $result[] = new QuoteComment(
                comment: (string) $comment->get('comment'),
                lineItemId: $this->nullableString($comment->get('quoteLineItemId')),
                createdById: $this->nullableString($comment->get('createdById')),
                customerId: $this->nullableString($comment->get('customerId')),
                createdAt: $createdAt instanceof \DateTimeInterface
                    ? \DateTimeImmutable::createFromInterface($createdAt)
                    : null,
                employeeId: $this->nullableString($comment->get('employeeId')),
            );
```

- [ ] **Step 6: Add `salesChannelId` to the identity**

`src/Bridge/Data/QuoteIdentity.php` becomes:

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
        public string $salesChannelId = '',
    ) {}
}
```

And in `src/Bridge/QuoteSnapshotReader.php`, `readIdentity` gains the field. `salesChannelId` is a plain column on `quote`, so no new association is needed on the criteria:

```php
    private function readIdentity(Entity $quote, string $quoteId): QuoteIdentity
    {
        $currency = $quote->get('currency');
        $iso = $currency instanceof Entity ? (string) $currency->get('isoCode') : '';
        $salesChannelId = $quote->get('salesChannelId');

        return new QuoteIdentity(
            quoteId: $quoteId,
            quoteNumber: (string) $quote->get('quoteNumber'),
            currencyIso: $iso,
            salesChannelId: \is_string($salesChannelId) ? $salesChannelId : '',
        );
    }
```

- [ ] **Step 7: Add the integration assertion**

Append this test method to `tests/Integration/FetchSnapshotTest.php` (keep the existing class declaration and its other methods; match the surrounding style):

```php
    /**
     * #18 selects a per-sales-channel policy from the snapshot rather than from
     * the servicing message, so the snapshot has to actually carry it.
     */
    public function testTheSnapshotCarriesTheSalesChannelId(): void
    {
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());

        $snapshot = static::gateway()->fetchSnapshot($quoteId);

        self::assertNotSame('', $snapshot->identity->salesChannelId, 'The quote has no sales channel.');
        self::assertTrue(Uuid::isValid($snapshot->identity->salesChannelId));
    }
```

Add `use Shopware\Core\Framework\Uuid\Uuid;` to that file's imports if it is not already there, and `use Shopware\Core\Framework\Context;` likewise.

- [ ] **Step 8: Run both suites**

Run: `composer run test -- --filter QuoteCommentTest`
Expected: PASS, 4 tests.

Run: `composer run test:integration -- --filter FetchSnapshotTest`
Expected: PASS, all existing tests plus the new one. If `assertTrue(Uuid::isValid(...))` fails, the quote genuinely has no sales channel — check the fixture quote with `QuoteFixture::anyQuoteId` rather than weakening the assertion.

- [ ] **Step 9: Run the quality gate**

Run: `composer run format:check && composer run lint && composer run typecheck`
Expected: clean.

- [ ] **Step 10: Commit**

```bash
git add src/Bridge/Data/QuoteComment.php src/Bridge/QuoteCommentMapper.php \
        src/Bridge/Data/QuoteIdentity.php src/Bridge/QuoteSnapshotReader.php \
        tests/Unit/Bridge/Data/QuoteCommentTest.php tests/Integration/FetchSnapshotTest.php
git commit -m "feat: read employeeId and salesChannelId into the quote snapshot

isAuthored() is the servicing fingerprint's discriminator: an agent comment is
author-less on createdById, customerId and employeeId alike (#3), so authorship
is what separates a buyer's ask from our own reply. salesChannelId lands on the
identity so #18 reads it off the snapshot instead of off the message."
```

---

### Task 4: Stamp the agent's own writes on the Context

Every write the agent makes must be recognisable in-process, so the listeners in Task 9 can skip it. One `Context` state does it for comments, transitions and line writes at once.

**Files:**
- Create: `src/Bridge/AgentContext.php`
- Modify: `src/Bridge/SwagCommercialQuoteGateway.php` (seven `Context::createDefaultContext()` call sites: lines 31, 38, 47, 53, 74, 94, 120)
- Test: `tests/Integration/AgentContextTest.php` (create)

**Interfaces:**
- Consumes: nothing.
- Produces: `AgentContext::STATE` (the string `merchant-quote-agent`) and `AgentContext::create(): Context`. Task 9's listeners call `$event->getContext()->hasState(AgentContext::STATE)`.

- [ ] **Step 1: Write the failing integration test**

This asserts the property on the *real* write path rather than against a mock, because the thing that could break is Shopware cloning or re-scoping the `Context` between our call and the written event.

Create `tests/Integration/AgentContextTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\AgentContext;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The whole re-entrancy design rests on one property: the Context state the
 * gateway stamps is still readable on the entity-written event SwagCommercial's
 * comment write produces. QuoteCommenter wraps its write in
 * `$context->scope(Context::CRUD_API_SCOPE, ...)`, and scope() strips states
 * pushed by an earlier scope() call — so this test exists to prove that a state
 * added OUTSIDE any scope survives it. If it ever fails, the servicing trigger
 * is silently re-entrant and QuoteServicingTrigger needs another discriminator.
 */
final class AgentContextTest extends IntegrationTestCase
{
    public function testAnAgentCommentWriteCarriesTheStateOnTheWrittenEvent(): void
    {
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $dispatcher = static::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        /** @var list<bool> $observed */
        $observed = [];
        $listener = static function (EntityWrittenEvent $event) use (&$observed): void {
            $observed[] = $event->getContext()->hasState(AgentContext::STATE);
        };

        $dispatcher->addListener('quote_comment.written', $listener);

        try {
            static::gateway()->addComment($quoteId, 'AgentContextTest probe');
        } finally {
            $dispatcher->removeListener('quote_comment.written', $listener);
        }

        self::assertNotEmpty($observed, 'No quote_comment.written event fired for an agent comment.');
        self::assertNotContains(
            false,
            $observed,
            'An agent comment write produced a quote_comment.written event whose Context had lost '
            . 'AgentContext::STATE. The servicing trigger cannot recognise its own writes.',
        );
    }

    public function testAPlainDefaultContextDoesNotCarryTheState(): void
    {
        self::assertFalse(Context::createDefaultContext()->hasState(AgentContext::STATE));
        self::assertTrue(AgentContext::create()->hasState(AgentContext::STATE));
    }
}
```

Remove the unused `EventSubscriberInterface` import if your editor does not — it is not needed; the test adds a closure listener.

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer run test:integration -- --filter AgentContextTest`

Expected: FAIL — `Class "MerchantQuoteAgentPlugin\Bridge\AgentContext" not found`.

- [ ] **Step 3: Create `AgentContext`**

Create `src/Bridge/AgentContext.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use Shopware\Core\Framework\Context;

/**
 * Every write the agent makes runs under a Context carrying STATE, so the
 * servicing trigger can tell its own writes from a buyer's and skip them.
 *
 * This replaces the author check the original design assumed: #3 measured
 * `createdById`, `customerId` and `employeeId` as ALL null on an agent comment,
 * and 42 of the test shop's 118 existing comments are author-less too — so null
 * does not mean "ours". A Context state does, and it covers comments, state
 * transitions and line-item writes with one check instead of one per surface.
 *
 * Its ceiling is that it only works in-process. That is where our own writes
 * happen: the message handler and the trigger run in the same worker. A write
 * arriving from outside this process is a buyer write by definition.
 *
 * Lives in Bridge rather than Servicing because the layer order is policy,
 * bridge, servicing: the bridge stamps, servicing reads.
 */
final class AgentContext
{
    public const STATE = 'merchant-quote-agent';

    private function __construct() {}

    public static function create(): Context
    {
        $context = Context::createDefaultContext();
        $context->addState(self::STATE);

        return $context;
    }
}
```

- [ ] **Step 4: Route every gateway write through it**

In `src/Bridge/SwagCommercialQuoteGateway.php`, replace all seven `Context::createDefaultContext()` calls with `AgentContext::create()`, and drop the now-unused `use Shopware\Core\Framework\Context;` **only if** no signature still needs it — `assertRevision(string $quoteId, ?QuoteRevision $expected, Context $context)` does, so keep the import.

The seven sites, one per method: `fetchSnapshot`, `updateLineItems`, `addProduct`, `recalculate`, `updateQuote`, `addComment`, `transition`. Replace every one. Verify with `grep -c 'Context::createDefaultContext()' src/Bridge/SwagCommercialQuoteGateway.php` — it must print 0 when you are done. `fetchSnapshot` is included deliberately: a read stamps nothing in the database, but stamping it uniformly means no future writer has to remember which contexts are stamped.

Example, `addComment`:

```php
    #[\Override]
    public function addComment(string $quoteId, string $comment): void
    {
        $context = AgentContext::create();
        // QuoteCommenter inserts blindly and lets the quote_comment foreign key
        // reject an unknown id, which would leak a Doctrine exception through this
        // interface. One redundant read keeps the isolation the interface promises
        // without declaring doctrine/dbal.
        $this->reader->read($quoteId, QuoteVersion::Live, $context);
        $this->lifecycle->comments->comment($quoteId, $comment, $context);
    }
```

`AgentContext` is in the same namespace, so no import is needed.

- [ ] **Step 5: Run the test to verify it passes**

Run: `composer run test:integration -- --filter AgentContextTest`

Expected: PASS, 2 tests.

- [ ] **Step 6: Run the full integration suite for regressions**

Run: `composer run test:integration`

Expected: every existing test still passes. The gateway now writes under a stamped context; nothing in the bridge reads states, so nothing should change. If `AddCommentTest` fails, read it before touching it — it pins authorship facts the fingerprint depends on.

- [ ] **Step 7: Run the quality gate and commit**

Run: `composer run format:check && composer run lint && composer run typecheck`
Expected: clean.

```bash
git add src/Bridge/AgentContext.php src/Bridge/SwagCommercialQuoteGateway.php \
        tests/Integration/AgentContextTest.php
git commit -m "feat: stamp agent writes with a Context state

The re-entrancy discriminator the original design wanted was the comment's
author, which #3 showed is null on all three fields for an agent comment and
for 42 of the shop's existing comments. The comment-level customFields stamp
is not reachable either: QuoteCommenter::comment() builds the row from a fixed
key list and returns void.

A Context state is one check covering comments, transitions and line writes.
AgentContextTest asserts it survives QuoteCommenter's scope() on the real write
path, because that is the part that could break silently."
```

---

### Task 5: The servicing fingerprint

The marker that decides whether a trigger is new work or a duplicate. Pure, no Shopware, fully unit-tested — this is the part that must never be wrong.

**Files:**
- Create: `src/Servicing/ServicingFingerprint.php`
- Test: `tests/Unit/Servicing/ServicingFingerprintTest.php` (create)

**Interfaces:**
- Consumes: `QuoteSnapshot`, `QuoteComment::isAuthored()` (Task 3).
- Produces:
  - `ServicingFingerprint::MARKER_KEY` = `'merchant_quote_agent_serviced'`
  - `ServicingFingerprint::of(QuoteSnapshot $snapshot): string`
  - `ServicingFingerprint::stamped(array $customFields): ?string`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Servicing/ServicingFingerprintTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteComment;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteContent;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTotals;
use MerchantQuoteAgentPlugin\Servicing\ServicingFingerprint;
use PHPUnit\Framework\TestCase;

/**
 * The fingerprint answers one question: has anything happened on this quote
 * that the agent has not already serviced? Deliberately NOT the quote's
 * revision — our own writes move `updatedAt`, so a revision marker would
 * differ from itself on the next pass and every duplicate trigger would look
 * like new work.
 */
final class ServicingFingerprintTest extends TestCase
{
    public function testIdenticalSnapshotsFingerprintIdentically(): void
    {
        $a = self::snapshot('open', [self::buyerComment('2026-08-27 10:00:00.100')]);
        $b = self::snapshot('open', [self::buyerComment('2026-08-27 10:00:00.100')]);

        self::assertSame(ServicingFingerprint::of($a), ServicingFingerprint::of($b));
    }

    /**
     * The property the whole design rests on. The agent's reply is author-less
     * (#3), so appending it must leave the fingerprint alone — otherwise the
     * agent's own write re-triggers servicing forever.
     */
    public function testAppendingAnAgentReplyDoesNotChangeTheFingerprint(): void
    {
        $before = self::snapshot('open', [self::buyerComment('2026-08-27 10:00:00.100')]);
        $after = self::snapshot('open', [
            self::buyerComment('2026-08-27 10:00:00.100'),
            new QuoteComment('agent reply', createdAt: new \DateTimeImmutable('2026-08-27 10:00:05.000')),
        ]);

        self::assertSame(ServicingFingerprint::of($before), ServicingFingerprint::of($after));
    }

    public function testAppendingABuyerCommentChangesTheFingerprint(): void
    {
        $before = self::snapshot('open', [self::buyerComment('2026-08-27 10:00:00.100')]);
        $after = self::snapshot('open', [
            self::buyerComment('2026-08-27 10:00:00.100'),
            self::buyerComment('2026-08-27 10:00:09.200'),
        ]);

        self::assertNotSame(ServicingFingerprint::of($before), ServicingFingerprint::of($after));
    }

    /**
     * A buyer comment that lands DURING a servicing pass is older than the
     * agent's reply, so "newest comment" alone would hide it. The authored
     * count is what catches it.
     */
    public function testABuyerCommentOlderThanTheAgentReplyStillChangesTheFingerprint(): void
    {
        $before = self::snapshot('open', [self::buyerComment('2026-08-27 10:00:00.100')]);
        $after = self::snapshot('open', [
            self::buyerComment('2026-08-27 10:00:00.100'),
            self::buyerComment('2026-08-27 10:00:03.000'),
            new QuoteComment('agent reply', createdAt: new \DateTimeImmutable('2026-08-27 10:00:07.000')),
        ]);

        self::assertNotSame(ServicingFingerprint::of($before), ServicingFingerprint::of($after));
    }

    public function testAStateChangeChangesTheFingerprint(): void
    {
        $open = self::snapshot('open', [self::buyerComment('2026-08-27 10:00:00.100')]);
        $replied = self::snapshot('replied', [self::buyerComment('2026-08-27 10:00:00.100')]);

        self::assertNotSame(ServicingFingerprint::of($open), ServicingFingerprint::of($replied));
    }

    public function testAQuoteWithNoCommentsFingerprintsWithoutError(): void
    {
        self::assertSame('open|0|0', ServicingFingerprint::of(self::snapshot('open', [])));
    }

    public function testAnAuthoredCommentWithNoTimestampDoesNotBreakTheMaximum(): void
    {
        $snapshot = self::snapshot('open', [
            new QuoteComment('no timestamp', customerId: 'c1'),
            self::buyerComment('2026-08-27 10:00:00.100'),
        ]);

        self::assertStringStartsWith('open|2|', ServicingFingerprint::of($snapshot));
    }

    public function testStampedReadsTheMarkerKeyAndNothingElse(): void
    {
        self::assertSame('open|1|123.000000', ServicingFingerprint::stamped([
            ServicingFingerprint::MARKER_KEY => 'open|1|123.000000',
            'unrelated' => 'value',
        ]));
    }

    public function testStampedIsNullWhenTheKeyIsAbsentOrNotAString(): void
    {
        self::assertNull(ServicingFingerprint::stamped([]));
        self::assertNull(ServicingFingerprint::stamped([ServicingFingerprint::MARKER_KEY => null]));
        self::assertNull(ServicingFingerprint::stamped([ServicingFingerprint::MARKER_KEY => 42]));
    }

    private static function buyerComment(string $createdAt): QuoteComment
    {
        return new QuoteComment(
            'buyer ask',
            customerId: 'customer-1',
            createdAt: new \DateTimeImmutable($createdAt),
        );
    }

    /** @param list<QuoteComment> $comments */
    private static function snapshot(string $state, array $comments): QuoteSnapshot
    {
        return new QuoteSnapshot(
            identity: new QuoteIdentity('q1', '10001', 'EUR', 'sc1'),
            revision: new QuoteRevision('v1', new \DateTimeImmutable('2026-08-27 10:00:00.000')),
            totals: new QuoteTotals(totalNet: 100.0),
            lifecycle: new QuoteLifecycle(stateTechnicalName: $state),
            content: new QuoteContent(comments: $comments),
        );
    }
}
```

Check `QuoteTotals`' constructor before running: if `totalNet` is not its first named parameter or `discount` is required, adjust the `snapshot()` helper to match. Read `src/Bridge/Data/QuoteTotals.php`.

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer run test -- --filter ServicingFingerprintTest`

Expected: FAIL — `Class "MerchantQuoteAgentPlugin\Servicing\ServicingFingerprint" not found`.

- [ ] **Step 3: Implement the fingerprint**

Create `src/Servicing/ServicingFingerprint.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteComment;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;

/**
 * A fingerprint of what the agent last serviced, persisted on the quote's
 * customFields. Comparing it against a fresh read is what makes a duplicate
 * trigger a no-op and a real buyer ask real work.
 *
 * Deliberately NOT the quote's revision. Only `updatedAt` moves (#3) and our
 * own servicing moves it — line prices, discount, expiration, state, and the
 * marker write itself — so a revision marker differs from itself on the next
 * pass and every duplicate trigger looks like new work. Revision-abort also
 * discards a buyer comment that lands mid-pass, which is a dropped ask rather
 * than a suppressed duplicate.
 *
 * The three components, and why each is there:
 *
 * - **state** — a transition is work. Moves on our own writes too
 *   (open → in_review → replied), which is why the handler stamps a value
 *   recomputed from a FRESH read after servicing, not the value it compared.
 * - **authored comment count** — catches a buyer comment wherever in the
 *   servicing window it lands, including one older than the agent's own reply,
 *   which a "newest comment" marker alone would hide.
 * - **newest authored createdAt** — distinguishes an edited or replaced comment
 *   from an appended one at the same count.
 *
 * An agent comment is author-less on createdById, customerId and employeeId
 * alike (#3, pinned by AddCommentTest), so it is excluded from both comment
 * components and cannot move the fingerprint. The 42 pre-existing author-less
 * comments in the test shop are historical and static, so they cannot either.
 */
final class ServicingFingerprint
{
    public const MARKER_KEY = 'merchant_quote_agent_serviced';

    private function __construct() {}

    public static function of(QuoteSnapshot $snapshot): string
    {
        $authored = array_filter(
            $snapshot->content->comments,
            static fn(QuoteComment $comment): bool => $comment->isAuthored(),
        );

        return implode('|', [
            $snapshot->lifecycle->stateTechnicalName,
            (string) \count($authored),
            self::newestCreatedAt($authored),
        ]);
    }

    /** @param array<string, mixed> $customFields */
    public static function stamped(array $customFields): ?string
    {
        $stamped = $customFields[self::MARKER_KEY] ?? null;

        return \is_string($stamped) ? $stamped : null;
    }

    /**
     * Compared and returned as a zero-padded 'U.u' string rather than a float:
     * the column is datetime(3) and a float comparison at microsecond scale is
     * exactly the kind of rounding this marker must not have. Both parts are
     * fixed-width, so string ordering is chronological ordering.
     *
     * @param array<int, QuoteComment> $comments
     */
    private static function newestCreatedAt(array $comments): string
    {
        $newest = '0';

        foreach ($comments as $comment) {
            $createdAt = $comment->createdAt?->format('U.u');

            if ($createdAt !== null && ($newest === '0' || $createdAt > $newest)) {
                $newest = $createdAt;
            }
        }

        return $newest;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer run test -- --filter ServicingFingerprintTest`

Expected: PASS, 9 tests.

- [ ] **Step 5: Run the quality gate and commit**

Run: `composer run format:check && composer run lint && composer run typecheck`
Expected: clean.

```bash
git add src/Servicing/ServicingFingerprint.php tests/Unit/Servicing/ServicingFingerprintTest.php
git commit -m "feat: fingerprint what the agent last serviced

State, authored-comment count and newest authored createdAt, persisted on the
quote's customFields. Not the quote revision: our own writes move updatedAt, so
a revision marker differs from itself on the next pass, and revision-abort
discards a buyer comment that lands mid-pass instead of suppressing a duplicate.

The authored-count component is what catches a buyer comment older than the
agent's own reply — a newest-comment marker alone hides it."
```

---

### Task 6: The message

**Files:**
- Create: `src/Servicing/Data/ServicingTriggerReason.php`
- Create: `src/Servicing/Data/ServiceQuoteMessage.php`
- Test: `tests/Unit/Servicing/Data/ServiceQuoteMessageTest.php` (create)

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `ServicingTriggerReason` enum with cases `StateEntered` (`'state_entered'`) and `CommentWritten` (`'comment_written'`).
  - `ServiceQuoteMessage::because(string $quoteId, ServicingTriggerReason $reason): self`
  - Readable properties `->quoteId` (string) and `->reason` (string).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Servicing/Data/ServiceQuoteMessageTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing\Data;

use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;
use MerchantQuoteAgentPlugin\Servicing\Data\ServicingTriggerReason;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

final class ServiceQuoteMessageTest extends TestCase
{
    public function testItRoutesToTheAsyncTransport(): void
    {
        $message = ServiceQuoteMessage::because('q1', ServicingTriggerReason::StateEntered);

        self::assertInstanceOf(AsyncMessageInterface::class, $message);
    }

    public function testItCarriesTheQuoteIdAndTheReasonsValue(): void
    {
        $message = ServiceQuoteMessage::because('q1', ServicingTriggerReason::CommentWritten);

        self::assertSame('q1', $message->quoteId);
        self::assertSame('comment_written', $message->reason);
    }

    /**
     * The payload is two plain strings on purpose: the `async` transport
     * serializes through messenger.transport.symfony_serializer, and a scalar
     * payload cannot fail to normalize. The enum types the call site; it does
     * not travel.
     */
    public function testTheSerialisedPayloadIsScalarOnly(): void
    {
        $message = ServiceQuoteMessage::because('q1', ServicingTriggerReason::StateEntered);

        foreach (get_object_vars($message) as $value) {
            self::assertIsString($value);
        }
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer run test -- --filter ServiceQuoteMessageTest`

Expected: FAIL — `Class "...\Servicing\Data\ServiceQuoteMessage" not found`.

- [ ] **Step 3: Create the reason enum**

Create `src/Servicing/Data/ServicingTriggerReason.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing\Data;

/**
 * Why a quote was queued for servicing. Exists for log and dead-letter triage,
 * not for behaviour: the handler re-reads the quote and decides from its state,
 * so nothing downstream branches on this. If #18 ever wants to branch on it,
 * that is a behavioural dependency and the enum needs designing rather than
 * extending.
 */
enum ServicingTriggerReason: string
{
    case StateEntered = 'state_entered';
    case CommentWritten = 'comment_written';
}
```

- [ ] **Step 4: Create the message**

Create `src/Servicing/Data/ServiceQuoteMessage.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing\Data;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

/**
 * "This quote needs servicing." Implementing AsyncMessageInterface is all the
 * routing this needs: Shopware's framework.messenger already routes that
 * interface to the `async` transport, whose retry strategy is max_retries 3
 * with exponential backoff and `failed` as the failure transport.
 *
 * The payload is the quote id and nothing that can go stale. Not the revision:
 * the handler re-reads the snapshot anyway and the snapshot is a better source
 * than a serialised copy. Not the sales-channel id: it rides on
 * QuoteIdentity::$salesChannelId, which the handler hands to #18.
 *
 * `$reason` is the enum's VALUE rather than the enum, because the `async`
 * transport serializes through messenger.transport.symfony_serializer and a
 * two-string payload cannot fail to normalize. `because()` keeps the call site
 * typed.
 */
final readonly class ServiceQuoteMessage implements AsyncMessageInterface
{
    private function __construct(
        public string $quoteId,
        public string $reason,
    ) {}

    public static function because(string $quoteId, ServicingTriggerReason $reason): self
    {
        return new self($quoteId, $reason->value);
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `composer run test -- --filter ServiceQuoteMessageTest`

Expected: PASS, 3 tests.

- [ ] **Step 6: Run the quality gate and commit**

Run: `composer run format:check && composer run lint && composer run typecheck`
Expected: clean.

```bash
git add src/Servicing/Data/ServicingTriggerReason.php \
        src/Servicing/Data/ServiceQuoteMessage.php \
        tests/Unit/Servicing/Data/ServiceQuoteMessageTest.php
git commit -m "feat: define the async quote servicing message

AsyncMessageInterface is the whole routing story — Shopware already routes it
to the async transport with retry and a failure transport. Payload is two
strings: the quote id, and the trigger reason for dead-letter triage. No
revision and no sales-channel id, both of which the handler reads off a fresh
snapshot rather than off a serialised copy that may already be stale."
```

---

### Task 7: The per-quote lock

**Files:**
- Create: `src/Servicing/QuoteServicingLock.php`
- Test: `tests/Unit/Servicing/QuoteServicingLockTest.php` (create)

**Interfaces:**
- Consumes: `Symfony\Component\Lock\LockFactory` (declared in Task 2).
- Produces:
  - `QuoteServicingLock::__construct(LockFactory $factory, string $lockDsn, ?LoggerInterface $logger = null)`
  - `QuoteServicingLock::for(string $quoteId): LockInterface`
  - `QuoteServicingLock::TTL_SECONDS` = `300.0`

- [ ] **Step 1: Write the failing test**

Tests use a real `LockFactory` over `InMemoryStore` rather than a mock — the behaviour under test is mutual exclusion, and a mock would assert the call rather than the property.

Create `tests/Unit/Servicing/QuoteServicingLockTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Servicing\QuoteServicingLock;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class QuoteServicingLockTest extends TestCase
{
    public function testTwoLocksForOneQuoteAreMutuallyExclusive(): void
    {
        $locks = new QuoteServicingLock(new LockFactory(new InMemoryStore()), 'redis://localhost');

        $first = $locks->for('quote-1');
        $second = $locks->for('quote-1');

        self::assertTrue($first->acquire());
        self::assertFalse($second->acquire());
    }

    public function testLocksForDifferentQuotesDoNotContend(): void
    {
        $locks = new QuoteServicingLock(new LockFactory(new InMemoryStore()), 'redis://localhost');

        self::assertTrue($locks->for('quote-1')->acquire());
        self::assertTrue($locks->for('quote-2')->acquire());
    }

    public function testAReleasedLockCanBeReacquired(): void
    {
        $locks = new QuoteServicingLock(new LockFactory(new InMemoryStore()), 'redis://localhost');

        $first = $locks->for('quote-1');
        self::assertTrue($first->acquire());
        $first->release();

        self::assertTrue($locks->for('quote-1')->acquire());
    }

    public function testAHostLocalStoreIsWarnedAboutExactlyOnce(): void
    {
        $logger = self::collectingLogger();
        $locks = new QuoteServicingLock(new LockFactory(new InMemoryStore()), 'flock', $logger);

        $locks->for('quote-1');
        $locks->for('quote-2');

        self::assertCount(1, $logger->records);
        self::assertStringContainsString('host-local', $logger->records[0]);
    }

    public function testASemaphoreStoreIsAlsoHostLocal(): void
    {
        $logger = self::collectingLogger();
        $locks = new QuoteServicingLock(new LockFactory(new InMemoryStore()), 'semaphore', $logger);

        $locks->for('quote-1');

        self::assertCount(1, $logger->records);
    }

    public function testASharedStoreIsNotWarnedAbout(): void
    {
        $logger = self::collectingLogger();
        $locks = new QuoteServicingLock(new LockFactory(new InMemoryStore()), 'redis://cache:6379', $logger);

        $locks->for('quote-1');

        self::assertSame([], $logger->records);
    }

    /** @return AbstractLogger&object{records: list<string>} */
    private static function collectingLogger(): object
    {
        return new class extends AbstractLogger {
            /** @var list<string> */
            public array $records = [];

            /**
             * @param mixed $level
             * @param string|\Stringable $message
             * @param array<string, mixed> $context
             */
            #[\Override]
            public function log($level, $message, array $context = []): void
            {
                $this->records[] = (string) $message;
            }
        };
    }
}
```

If Mago rejects the `@return AbstractLogger&object{...}` intersection annotation, assign the anonymous class to a local variable inside each test instead of using the helper — do not weaken it to `object`.

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer run test -- --filter QuoteServicingLockTest`

Expected: FAIL — `Class "MerchantQuoteAgentPlugin\Servicing\QuoteServicingLock" not found`.

- [ ] **Step 3: Implement the lock**

Create `src/Servicing/QuoteServicingLock.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * One lock per quote id, replacing the in-process `inFlight` map of the
 * TypeScript path that was only correct while exactly one instance ran.
 *
 * The TTL matters as much as the lock. `addProduct` on a variant product
 * segfaults the PHP process (exit 139, no exception — #3), so a failure can be
 * a dropped worker rather than a thrown error, and a lock with no TTL would
 * wedge that quote permanently. Not auto-refreshed: a pass that outlives the
 * TTL is a problem to see in the logs, not to paper over.
 *
 * The store is the merchant's, taken from the shop's LOCK_DSN — the plugin does
 * not substitute its own, because a lock store that disagrees with everything
 * else in the shop is worse than a documented ceiling. `flock` (Shopware's
 * default, set in core's own framework.yaml) and `semaphore` are host-local, so
 * they give no cross-node exclusion; that gets one warning rather than silence.
 */
final class QuoteServicingLock
{
    public const TTL_SECONDS = 300.0;

    private const KEY_PREFIX = 'merchant-quote-agent.quote.';

    /** Symfony store DSNs that only coordinate processes on one host. */
    private const HOST_LOCAL_DSN_PREFIXES = ['flock', 'semaphore'];

    private bool $warned = false;

    public function __construct(
        private readonly LockFactory $factory,
        private readonly string $lockDsn,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function for(string $quoteId): LockInterface
    {
        $this->warnOnceIfHostLocal();

        return $this->factory->createLock(self::KEY_PREFIX . $quoteId, self::TTL_SECONDS);
    }

    private function warnOnceIfHostLocal(): void
    {
        if ($this->warned) {
            return;
        }

        $this->warned = true;

        foreach (self::HOST_LOCAL_DSN_PREFIXES as $prefix) {
            if (!str_starts_with($this->lockDsn, $prefix)) {
                continue;
            }

            $this->logger?->warning(
                'Quote servicing locks are host-local, so two workers on different hosts can '
                . 'service one quote at the same time and race each other. Point LOCK_DSN at a '
                . 'shared store before running message workers on more than one node.',
                ['lockDsn' => $this->lockDsn],
            );

            return;
        }
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer run test -- --filter QuoteServicingLockTest`

Expected: PASS, 6 tests.

- [ ] **Step 5: Run the quality gate and commit**

Run: `composer run format:check && composer run lint && composer run typecheck`
Expected: clean.

```bash
git add src/Servicing/QuoteServicingLock.php tests/Unit/Servicing/QuoteServicingLockTest.php
git commit -m "feat: one TTL-bounded lock per quote id

Replaces the in-process inFlight map that was only correct while exactly one
instance ran. The 300s TTL is the part that handles worker death: addProduct on
a variant product segfaults the process, so a lock with no TTL would wedge that
quote forever.

The store stays the merchant's. Shopware defaults LOCK_DSN to flock, which is
host-local and gives no cross-node exclusion, so that gets one warning instead
of a silently wrong guarantee."
```

---

### Task 8: The message handler

Where the lock, the fingerprint and the crash counter come together, and where #18 gets handed a claimed quote.

**Files:**
- Create: `src/Servicing/QuoteServicingPipelineInterface.php`
- Create: `src/Servicing/ServiceQuoteHandler.php`
- Test: `tests/Unit/Servicing/ServiceQuoteHandlerTest.php` (create)
- Test: `tests/Unit/Servicing/FakeQuoteGateway.php` (create — a shared test double)

**Interfaces:**
- Consumes: `ServiceQuoteMessage` (Task 6), `ServicingFingerprint` (Task 5), `QuoteServicingLock` (Task 7), `QuoteGatewayInterface`, `QuoteUpdate`, `QuoteNotFoundException`.
- Produces:
  - `QuoteServicingPipelineInterface::service(QuoteSnapshot $snapshot, QuoteGatewayInterface $gateway): void`
  - `ServiceQuoteHandler::ATTEMPTS_KEY` = `'merchant_quote_agent_attempts'`, `ServiceQuoteHandler::MAX_ATTEMPTS` = `4`
  - `ServiceQuoteHandler::__invoke(ServiceQuoteMessage $message): void`

- [ ] **Step 1: Create the pipeline seam**

Create `src/Servicing/QuoteServicingPipelineInterface.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;

/**
 * The seam issue #18 fills in: snapshot → interpret ask → propose → authorize
 * → apply → verify → reply or escalate. Issue #4 registers no implementation,
 * so the handler's collaborator is null and a claimed quote is a log line.
 *
 * The gateway is a parameter rather than an implementer's constructor
 * dependency on purpose. The handler has already established that it is
 * non-null and licensed, and that it holds the lock; handing the same instance
 * over means #18 cannot end up with a second, differently-resolved gateway —
 * or a null one.
 */
interface QuoteServicingPipelineInterface
{
    public function service(QuoteSnapshot $snapshot, QuoteGatewayInterface $gateway): void;
}
```

- [ ] **Step 2: Create the fake gateway the handler tests need**

Create `tests/Unit/Servicing/FakeQuoteGateway.php`. It records `updateQuote` calls in order, which is what the crash-counter tests assert on:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineItemChange;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteVersion;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Bridge\QuoteNotFoundException;

/**
 * Records the writes the handler makes and serves a queue of snapshots, so a
 * test can make the second read differ from the first the way a real servicing
 * pass does.
 */
final class FakeQuoteGateway implements QuoteGatewayInterface
{
    /** @var list<array<string, mixed>> */
    public array $customFieldWrites = [];

    /** @var list<string> */
    public array $calls = [];

    /** @param list<QuoteSnapshot> $snapshots served in order; the last one repeats */
    public function __construct(
        private array $snapshots,
        private readonly bool $quoteMissing = false,
    ) {}

    #[\Override]
    public function fetchSnapshot(string $quoteId, QuoteVersion $version = QuoteVersion::Live): QuoteSnapshot
    {
        if ($this->quoteMissing) {
            throw QuoteNotFoundException::forId($quoteId);
        }

        $this->calls[] = 'fetchSnapshot';

        return \count($this->snapshots) > 1 ? array_shift($this->snapshots) : $this->snapshots[0];
    }

    #[\Override]
    public function updateQuote(string $quoteId, QuoteUpdate $update, ?QuoteRevision $expected = null): void
    {
        $this->calls[] = 'updateQuote';

        if ($update->customFields !== null) {
            $this->customFieldWrites[] = $update->customFields;
        }
    }

    /** @param list<QuoteLineItemChange> $changes */
    #[\Override]
    public function updateLineItems(string $quoteId, array $changes, ?QuoteRevision $expected = null): void {}

    #[\Override]
    public function addProduct(string $quoteId, string $productId, int $quantity): void {}

    #[\Override]
    public function recalculate(string $quoteId): void {}

    #[\Override]
    public function addComment(string $quoteId, string $comment): void {}

    #[\Override]
    public function transition(string $quoteId, QuoteTransition $action): void {}
}
```

Check `QuoteNotFoundException::forId()` exists with that name — `SwagCommercialQuoteGateway` and `QuoteSnapshotReader` both call it, so it does.

- [ ] **Step 3: Write the failing handler test**

Create `tests/Unit/Servicing/ServiceQuoteHandlerTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteContent;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTotals;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;
use MerchantQuoteAgentPlugin\Servicing\Data\ServicingTriggerReason;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingLock;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingPipelineInterface;
use MerchantQuoteAgentPlugin\Servicing\ServiceQuoteHandler;
use MerchantQuoteAgentPlugin\Servicing\ServicingFingerprint;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class ServiceQuoteHandlerTest extends TestCase
{
    public function testANewFingerprintHandsOffOnceAndStampsTheMarker(): void
    {
        $gateway = new FakeQuoteGateway([self::snapshot()]);
        $pipeline = self::countingPipeline();
        $handler = self::handler($gateway, $pipeline);

        $handler(self::message());

        self::assertSame(1, $pipeline->passes);
        $stamp = $gateway->customFieldWrites[array_key_last($gateway->customFieldWrites)];
        self::assertArrayHasKey(ServicingFingerprint::MARKER_KEY, $stamp);
        self::assertNull($stamp[ServiceQuoteHandler::ATTEMPTS_KEY]);
    }

    public function testAMatchingFingerprintHandsOffZeroTimes(): void
    {
        $snapshot = self::snapshot();
        $serviced = self::snapshot([ServicingFingerprint::MARKER_KEY => ServicingFingerprint::of($snapshot)]);
        $gateway = new FakeQuoteGateway([$serviced]);
        $pipeline = self::countingPipeline();

        self::handler($gateway, $pipeline)(self::message());

        self::assertSame(0, $pipeline->passes);
        self::assertSame([], $gateway->customFieldWrites);
    }

    public function testTheCounterIsIncrementedBeforeTheHandOff(): void
    {
        $gateway = new FakeQuoteGateway([self::snapshot()]);
        $pipeline = new class implements QuoteServicingPipelineInterface {
            public ?int $writesSeenBeforeMe = null;

            #[\Override]
            public function service(QuoteSnapshot $snapshot, QuoteGatewayInterface $gateway): void
            {
                \PHPUnit\Framework\Assert::assertInstanceOf(FakeQuoteGateway::class, $gateway);
                $this->writesSeenBeforeMe = \count($gateway->customFieldWrites);
            }
        };

        self::handler($gateway, $pipeline)(self::message());

        self::assertSame(
            1,
            $pipeline->writesSeenBeforeMe,
            'The crash counter must be committed BEFORE the pipeline runs — that ordering is the '
            . 'entire mechanism. A segfault during service() leaves no exception and no retry '
            . 'stamp, so an uncommitted counter means an hourly redelivery loop forever.',
        );
    }

    public function testAQuoteAtTheAttemptCeilingParksWithoutHandingOff(): void
    {
        $gateway = new FakeQuoteGateway([
            self::snapshot([ServiceQuoteHandler::ATTEMPTS_KEY => ServiceQuoteHandler::MAX_ATTEMPTS]),
        ]);
        $pipeline = self::countingPipeline();

        $this->expectException(UnrecoverableMessageHandlingException::class);

        try {
            self::handler($gateway, $pipeline)(self::message());
        } finally {
            self::assertSame(0, $pipeline->passes);
        }
    }

    public function testAHeldLockIsRetriedRatherThanDropped(): void
    {
        $locks = new QuoteServicingLock(new LockFactory(new InMemoryStore()), 'redis://x');
        $locks->for('q1')->acquire();

        $gateway = new FakeQuoteGateway([self::snapshot()]);
        $pipeline = self::countingPipeline();
        $handler = new ServiceQuoteHandler($locks, new NullLogger(), $gateway, $pipeline);

        $this->expectException(RecoverableMessageHandlingException::class);

        try {
            $handler(self::message());
        } finally {
            self::assertSame(0, $pipeline->passes);
        }
    }

    public function testANullGatewayReturnsWithoutServicingOrLocking(): void
    {
        $locks = self::locks();
        $pipeline = self::countingPipeline();

        (new ServiceQuoteHandler($locks, new NullLogger(), null, $pipeline))(self::message());

        self::assertSame(0, $pipeline->passes, 'A quote was serviced without a gateway.');
        self::assertTrue(
            $locks->for('q1')->acquire(),
            'The handler took a lock before checking the gateway, so an unlicensed shop still '
            . 'serialises on a lock it can never use.',
        );
    }

    public function testANullPipelineReturnsWithoutStamping(): void
    {
        $gateway = new FakeQuoteGateway([self::snapshot()]);

        new ServiceQuoteHandler(self::locks(), new NullLogger(), $gateway, null)(self::message());

        self::assertSame([], $gateway->customFieldWrites, 'Nothing was serviced, so nothing may be stamped.');
    }

    public function testADeletedQuoteIsSwallowedRatherThanRetried(): void
    {
        $gateway = new FakeQuoteGateway([self::snapshot()], quoteMissing: true);
        $pipeline = self::countingPipeline();

        self::handler($gateway, $pipeline)(self::message());

        self::assertSame(0, $pipeline->passes);
    }

    public function testTheLockIsReleasedSoASecondPassCanRun(): void
    {
        $locks = self::locks();
        $gateway = new FakeQuoteGateway([self::snapshot()]);
        $handler = new ServiceQuoteHandler($locks, new NullLogger(), $gateway, self::countingPipeline());

        $handler(self::message());

        self::assertTrue($locks->for('q1')->acquire(), 'The handler did not release its lock.');
    }

    private static function locks(): QuoteServicingLock
    {
        return new QuoteServicingLock(new LockFactory(new InMemoryStore()), 'redis://x');
    }

    private static function handler(
        FakeQuoteGateway $gateway,
        QuoteServicingPipelineInterface $pipeline,
    ): ServiceQuoteHandler {
        return new ServiceQuoteHandler(self::locks(), new NullLogger(), $gateway, $pipeline);
    }

    private static function message(): ServiceQuoteMessage
    {
        return ServiceQuoteMessage::because('q1', ServicingTriggerReason::CommentWritten);
    }

    /** @return QuoteServicingPipelineInterface&object{passes: int} */
    private static function countingPipeline(): object
    {
        return new class implements QuoteServicingPipelineInterface {
            public int $passes = 0;

            #[\Override]
            public function service(QuoteSnapshot $snapshot, QuoteGatewayInterface $gateway): void
            {
                ++$this->passes;
            }
        };
    }

    /** @param array<string, mixed> $customFields */
    private static function snapshot(array $customFields = []): QuoteSnapshot
    {
        return new QuoteSnapshot(
            identity: new QuoteIdentity('q1', '10001', 'EUR', 'sc1'),
            revision: new QuoteRevision('v1', new \DateTimeImmutable('2026-08-27 10:00:00.000')),
            totals: new QuoteTotals(totalNet: 100.0),
            lifecycle: new QuoteLifecycle(stateTechnicalName: 'open', customFields: $customFields),
            content: new QuoteContent(),
        );
    }
}
```

- [ ] **Step 4: Run the test to verify it fails**

Run: `composer run test -- --filter ServiceQuoteHandlerTest`

Expected: FAIL — `Class "MerchantQuoteAgentPlugin\Servicing\ServiceQuoteHandler" not found`.

- [ ] **Step 5: Implement the handler**

Create `src/Servicing/ServiceQuoteHandler.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Bridge\QuoteNotFoundException;
use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Claims a quote, decides whether anything actually needs servicing, and hands
 * it to #18. Never runs in the triggering request — LLM latency is seconds.
 */
#[AsMessageHandler]
final readonly class ServiceQuoteHandler
{
    /**
     * A crash budget, not a retry budget. Messenger's `max_retries: 3` is
     * driven by a RedeliveryStamp that SendFailedMessageForRetryListener adds on
     * WorkerMessageFailedEvent — i.e. only when a handler THROWS. A segfault
     * (exit 139, `addProduct` on a variant product, #3) emits no event and
     * accrues no stamp, so the doctrine transport reclaims the row after
     * redeliver_timeout (3600s) and redelivers with retry count 0, hourly,
     * forever. This counter is what bounds that, and it is 4 so that a crash
     * budget and a throw budget are the same size.
     */
    public const MAX_ATTEMPTS = 4;

    public const ATTEMPTS_KEY = 'merchant_quote_agent_attempts';

    public function __construct(
        private QuoteServicingLock $locks,
        private LoggerInterface $logger,
        private ?QuoteGatewayInterface $gateway = null,
        private ?QuoteServicingPipelineInterface $pipeline = null,
    ) {}

    public function __invoke(ServiceQuoteMessage $message): void
    {
        $gateway = $this->gateway;

        if ($gateway === null) {
            // Not a silent no-op: QuoteGatewayFactory returns null when
            // SwagCommercial is absent or unlicensed (#3), and a quote that
            // was queued and then not serviced is worth a line in the log.
            $this->logger->warning('Quote queued for servicing but the SwagCommercial gateway is unavailable.', [
                'quoteId' => $message->quoteId,
                'reason' => $message->reason,
            ]);

            return;
        }

        $lock = $this->locks->for($message->quoteId);

        if (!$lock->acquire()) {
            // Deliberately not a silent return: another worker holds this
            // quote, and after it finishes the fingerprint may STILL differ —
            // a buyer comment that landed mid-pass. Dropping the message here
            // would drop that ask. Messenger's backoff does the waiting.
            throw new RecoverableMessageHandlingException(
                sprintf('Quote %s is being serviced by another worker.', $message->quoteId),
            );
        }

        try {
            $this->servicePass($gateway, $message);
        } catch (QuoteNotFoundException $e) {
            // A quote deleted between trigger and handling is not a failure
            // worth retrying. Every other throwable propagates to Messenger.
            $this->logger->warning('Quote queued for servicing no longer exists.', [
                'quoteId' => $message->quoteId,
                'exception' => $e,
            ]);
        } finally {
            $lock->release();
        }
    }

    /** @throws QuoteNotFoundException */
    private function servicePass(QuoteGatewayInterface $gateway, ServiceQuoteMessage $message): void
    {
        $snapshot = $gateway->fetchSnapshot($message->quoteId);
        $fingerprint = ServicingFingerprint::of($snapshot);

        if ($fingerprint === ServicingFingerprint::stamped($snapshot->lifecycle->customFields)) {
            $this->logger->debug('Nothing has happened on this quote since the last servicing pass.', [
                'quoteId' => $message->quoteId,
                'fingerprint' => $fingerprint,
            ]);

            return;
        }

        $pipeline = $this->pipeline;

        if ($pipeline === null) {
            // Returns BEFORE stamping: nothing was serviced, so claiming it was
            // would suppress the next real trigger.
            $this->logger->warning('Quote claimed for servicing but no servicing pipeline is registered.', [
                'quoteId' => $message->quoteId,
            ]);

            return;
        }

        $this->claimAttempt($gateway, $message, $snapshot);

        $pipeline->service($snapshot, $gateway);

        // Recomputed from a FRESH read, not from $fingerprint: the state
        // component moves on our own writes (open → in_review → replied), so
        // stamping the compared value would leave the quote looking unserviced.
        $after = $gateway->fetchSnapshot($message->quoteId);

        $gateway->updateQuote($message->quoteId, new QuoteUpdate(customFields: [
            ServicingFingerprint::MARKER_KEY => ServicingFingerprint::of($after),
            self::ATTEMPTS_KEY => null,
        ]));
    }

    private function claimAttempt(
        QuoteGatewayInterface $gateway,
        ServiceQuoteMessage $message,
        QuoteSnapshot $snapshot,
    ): void {
        $attempts = $snapshot->lifecycle->customFields[self::ATTEMPTS_KEY] ?? 0;
        $attempts = \is_int($attempts) ? $attempts : 0;

        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->logger->error(
                'Servicing this quote has failed {attempts} times without a thrown error, which means '
                . 'it is killing the worker process. Parking the message. Clear the "{key}" custom '
                . 'field on the quote to let the agent try again.',
                ['attempts' => $attempts, 'key' => self::ATTEMPTS_KEY, 'quoteId' => $message->quoteId],
            );

            throw new UnrecoverableMessageHandlingException(
                sprintf('Quote %s exceeded the servicing crash budget.', $message->quoteId),
            );
        }

        // Committed before the pipeline runs, so it survives a process death
        // during it. This ordering IS the mechanism.
        $gateway->updateQuote($message->quoteId, new QuoteUpdate(customFields: [
            self::ATTEMPTS_KEY => $attempts + 1,
        ]));
    }
}
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `composer run test -- --filter ServiceQuoteHandlerTest`

Expected: PASS, 9 tests. If `testTheCounterIsIncrementedBeforeTheHandOff` fails, the counter write moved after the hand-off — put it back; that ordering is the whole crash mechanism.

- [ ] **Step 7: Run the quality gate**

Run: `composer run format:check && composer run lint && composer run typecheck`

Expected: clean. If Mago reports cyclomatic complexity over 10 on `__invoke` or `servicePass`, do not raise the threshold — extract the null-gateway branch into a private predicate.

- [ ] **Step 8: Commit**

```bash
git add src/Servicing/QuoteServicingPipelineInterface.php src/Servicing/ServiceQuoteHandler.php \
        tests/Unit/Servicing/ServiceQuoteHandlerTest.php tests/Unit/Servicing/FakeQuoteGateway.php
git commit -m "feat: claim a quote under a lock, then hand it to the pipeline

Lock, fingerprint, crash counter, hand-off. Three things worth knowing:

A held lock throws RecoverableMessageHandlingException rather than returning.
Dropping the message would drop a buyer comment that landed mid-pass, since
the fingerprint may still differ once the other worker finishes.

The crash counter is committed BEFORE the pipeline runs. Messenger's retry
budget rides on a RedeliveryStamp added only when a handler throws, so a
segfault accrues none and the doctrine transport redelivers hourly forever.
The counter bounds that; the ordering is the mechanism.

The marker is stamped from a fresh read, not from the compared fingerprint:
the state component moves on our own writes."
```

---

### Task 9: The trigger

**Files:**
- Create: `src/Servicing/QuoteServicingTrigger.php`
- Test: `tests/Unit/Servicing/QuoteServicingTriggerTest.php` (create)

**Interfaces:**
- Consumes: `ServiceQuoteMessage` (Task 6), `AgentContext::STATE` (Task 4).
- Produces: `QuoteServicingTrigger::__construct(MessageBusInterface $bus)`, subscribed to `state_machine.quote.state_changed` and `quote_comment.written`.

- [ ] **Step 1: Write the failing test**

Note two facts the test relies on. `StateMachineStateChangeEvent`'s constructor reads `$nextState->getTechnicalName()`, so the next-state entity must have one set. And its `getSalesChannelId()` reads a declared-but-never-assigned property, so never call it.

Create `tests/Unit/Servicing/QuoteServicingTriggerTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Bridge\AgentContext;
use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingTrigger;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Shopware\Core\System\StateMachine\StateMachineEntity;
use Shopware\Core\System\StateMachine\Transition;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class QuoteServicingTriggerTest extends TestCase
{
    public function testItSubscribesToTwoCoreEventNames(): void
    {
        self::assertSame(
            ['state_machine.quote.state_changed', 'quote_comment.written'],
            array_keys(QuoteServicingTrigger::getSubscribedEvents()),
        );
    }

    public function testEnteringOpenQueuesTheQuote(): void
    {
        $bus = self::collectingBus();
        (new QuoteServicingTrigger($bus))->onQuoteStateChanged(self::stateEvent('open'));

        self::assertCount(1, $bus->messages);
        self::assertSame('q1', $bus->messages[0]->quoteId);
        self::assertSame('state_entered', $bus->messages[0]->reason);
    }

    public function testEnteringChangeRequestedQueuesTheQuote(): void
    {
        $bus = self::collectingBus();
        (new QuoteServicingTrigger($bus))->onQuoteStateChanged(self::stateEvent('change_requested'));

        self::assertCount(1, $bus->messages);
    }

    /**
     * `in_review` and `replied` are the states the agent's OWN servicing drives.
     * Keeping them out of the trigger set means a self-trigger cannot happen
     * even if the context stamp were ever lost.
     */
    public function testEnteringAStateTheAgentItselfDrivesQueuesNothing(): void
    {
        $bus = self::collectingBus();
        $trigger = new QuoteServicingTrigger($bus);

        $trigger->onQuoteStateChanged(self::stateEvent('in_review'));
        $trigger->onQuoteStateChanged(self::stateEvent('replied'));
        $trigger->onQuoteStateChanged(self::stateEvent('accepted'));
        $trigger->onQuoteStateChanged(self::stateEvent('draft'));

        self::assertSame([], $bus->messages);
    }

    public function testTheLeaveSideOfATransitionQueuesNothing(): void
    {
        $bus = self::collectingBus();
        $event = self::stateEvent('open', StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_LEAVE);

        (new QuoteServicingTrigger($bus))->onQuoteStateChanged($event);

        self::assertSame([], $bus->messages);
    }

    public function testAnAgentDrivenTransitionQueuesNothing(): void
    {
        $bus = self::collectingBus();
        $event = self::stateEvent('open', context: AgentContext::create());

        (new QuoteServicingTrigger($bus))->onQuoteStateChanged($event);

        self::assertSame([], $bus->messages);
    }

    public function testAnInsertedCommentQueuesItsQuote(): void
    {
        $bus = self::collectingBus();
        $event = self::commentEvent([self::insert('q1')]);

        (new QuoteServicingTrigger($bus))->onQuoteCommentWritten($event);

        self::assertCount(1, $bus->messages);
        self::assertSame('comment_written', $bus->messages[0]->reason);
    }

    public function testTwoCommentsOnOneQuoteQueueItOnce(): void
    {
        $bus = self::collectingBus();
        $event = self::commentEvent([self::insert('q1'), self::insert('q1')]);

        (new QuoteServicingTrigger($bus))->onQuoteCommentWritten($event);

        self::assertCount(1, $bus->messages);
    }

    public function testCommentsOnTwoQuotesQueueBoth(): void
    {
        $bus = self::collectingBus();
        $event = self::commentEvent([self::insert('q1'), self::insert('q2')]);

        (new QuoteServicingTrigger($bus))->onQuoteCommentWritten($event);

        self::assertCount(2, $bus->messages);
    }

    public function testAnEditedCommentQueuesNothing(): void
    {
        $bus = self::collectingBus();
        $update = new EntityWriteResult(
            'c1',
            ['quoteId' => 'q1', 'comment' => 'edited'],
            'quote_comment',
            EntityWriteResult::OPERATION_UPDATE,
        );

        (new QuoteServicingTrigger($bus))->onQuoteCommentWritten(self::commentEvent([$update]));

        self::assertSame([], $bus->messages);
    }

    public function testACommentInsertWithNoQuoteIdQueuesNothing(): void
    {
        $bus = self::collectingBus();
        $orphan = new EntityWriteResult('c1', ['comment' => 'x'], 'quote_comment', EntityWriteResult::OPERATION_INSERT);

        (new QuoteServicingTrigger($bus))->onQuoteCommentWritten(self::commentEvent([$orphan]));

        self::assertSame([], $bus->messages);
    }

    public function testAnAgentAuthoredCommentQueuesNothing(): void
    {
        $bus = self::collectingBus();
        $event = self::commentEvent([self::insert('q1')], AgentContext::create());

        (new QuoteServicingTrigger($bus))->onQuoteCommentWritten($event);

        self::assertSame([], $bus->messages);
    }

    private static function insert(string $quoteId): EntityWriteResult
    {
        return new EntityWriteResult(
            'c-' . $quoteId,
            ['quoteId' => $quoteId, 'comment' => 'buyer ask'],
            'quote_comment',
            EntityWriteResult::OPERATION_INSERT,
        );
    }

    /** @param list<EntityWriteResult> $results */
    private static function commentEvent(array $results, ?Context $context = null): EntityWrittenEvent
    {
        return new EntityWrittenEvent('quote_comment', $results, $context ?? Context::createDefaultContext());
    }

    private static function stateEvent(
        string $nextState,
        string $side = StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER,
        ?Context $context = null,
    ): StateMachineStateChangeEvent {
        $machine = new StateMachineEntity();
        $machine->setTechnicalName('quote.state');

        $from = new StateMachineStateEntity();
        $from->setTechnicalName('draft');

        $to = new StateMachineStateEntity();
        $to->setTechnicalName($nextState);

        return new StateMachineStateChangeEvent(
            $context ?? Context::createDefaultContext(),
            $side,
            new Transition('quote', 'q1', 'customer_send', 'stateId'),
            $machine,
            $from,
            $to,
        );
    }

    /** @return MessageBusInterface&object{messages: list<ServiceQuoteMessage>} */
    private static function collectingBus(): object
    {
        return new class implements MessageBusInterface {
            /** @var list<ServiceQuoteMessage> */
            public array $messages = [];

            /**
             * @param object|Envelope $message
             * @param array<array-key, \Symfony\Component\Messenger\Stamp\StampInterface> $stamps
             */
            #[\Override]
            public function dispatch($message, array $stamps = []): Envelope
            {
                // Assert::, not self:: — inside an anonymous class self:: is the
                // anonymous class, which has no assertion methods.
                \PHPUnit\Framework\Assert::assertInstanceOf(ServiceQuoteMessage::class, $message);
                $this->messages[] = $message;

                return new Envelope($message);
            }
        };
    }
}
```

`StateMachineStateEntity` and `StateMachineEntity` may require an `id` before other setters work; if a test errors with "must not be accessed before initialization", call `->setId(Uuid::randomHex())` on each. `Transition`'s constructor is `(string $entityName, string $entityId, string $transitionName, string $stateFieldName)` — confirm against `vendor/shopware/core/System/StateMachine/Transition.php` and adjust if it differs.

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer run test -- --filter QuoteServicingTriggerTest`

Expected: FAIL — `Class "MerchantQuoteAgentPlugin\Servicing\QuoteServicingTrigger" not found`.

- [ ] **Step 3: Implement the trigger**

Create `src/Servicing/QuoteServicingTrigger.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use MerchantQuoteAgentPlugin\Bridge\AgentContext;
use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;
use MerchantQuoteAgentPlugin\Servicing\Data\ServicingTriggerReason;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Normalises the two things that mean "this quote needs servicing" into one
 * message. Replaces the webhook path outright: nothing to verify per Shopware
 * build, no Flow Builder fallback to document.
 *
 * Both subscriptions are on CORE classes and core event names. SwagCommercial
 * dispatches its own `state_enter.quote.state.*` events carrying a QuoteEntity,
 * but those classes are `@internal` there, and ADR 0001 confines untyped
 * commercial access to the bridge's adapters — the core state-change event
 * carries everything needed, so there is no reason to widen that surface.
 *
 * `quote.requested` needs no separate subscription: a buyer's request runs
 * through the `customer_send` transition (draft → open), so the state
 * subscription already covers it. Verified against the shop's own
 * state_machine_transition table.
 */
final readonly class QuoteServicingTrigger implements EventSubscriberInterface
{
    /**
     * The only two states that mean the agent has something to do. Notably NOT
     * `in_review` or `replied`: those are the states the agent's own servicing
     * drives, so leaving them out means a self-trigger is impossible by
     * construction, independently of the context stamp.
     */
    private const TRIGGER_STATES = ['open', 'change_requested'];

    public function __construct(
        private MessageBusInterface $bus,
    ) {}

    /** @return array<string, string> */
    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            // Dispatched by core's StateMachineRegistry as
            // 'state_machine.' . $machine->getTechnicalName() . '_changed',
            // and the quote machine's technical name is 'quote.state'.
            'state_machine.quote.state_changed' => 'onQuoteStateChanged',
            'quote_comment.written' => 'onQuoteCommentWritten',
        ];
    }

    public function onQuoteStateChanged(StateMachineStateChangeEvent $event): void
    {
        if (self::isAgentWrite($event->getContext())) {
            return;
        }

        // Fires twice per transition, leave then enter. Only entering a state
        // is news.
        if ($event->getTransitionSide() !== StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER) {
            return;
        }

        if (!\in_array($event->getStateName(), self::TRIGGER_STATES, strict: true)) {
            return;
        }

        $this->queue($event->getTransition()->getEntityId(), ServicingTriggerReason::StateEntered);
    }

    public function onQuoteCommentWritten(EntityWrittenEvent $event): void
    {
        if (self::isAgentWrite($event->getContext())) {
            return;
        }

        /** @var array<string, true> $queued */
        $queued = [];

        foreach ($event->getWriteResults() as $result) {
            // An edited comment is not a new ask.
            if ($result->getOperation() !== EntityWriteResult::OPERATION_INSERT) {
                continue;
            }

            $quoteId = $result->getPayload()['quoteId'] ?? null;

            if (!\is_string($quoteId) || isset($queued[$quoteId])) {
                continue;
            }

            $queued[$quoteId] = true;
            $this->queue($quoteId, ServicingTriggerReason::CommentWritten);
        }
    }

    private static function isAgentWrite(Context $context): bool
    {
        return $context->hasState(AgentContext::STATE);
    }

    private function queue(string $quoteId, ServicingTriggerReason $reason): void
    {
        // Never serviced in the triggering request: LLM latency is seconds.
        $this->bus->dispatch(ServiceQuoteMessage::because($quoteId, $reason));
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer run test -- --filter QuoteServicingTriggerTest`

Expected: PASS, 12 tests.

- [ ] **Step 5: Run the whole unit suite and the quality gate**

Run: `composer run test`
Expected: PASS, all suites.

Run: `composer run format:check && composer run lint && composer run typecheck`
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add src/Servicing/QuoteServicingTrigger.php tests/Unit/Servicing/QuoteServicingTriggerTest.php
git commit -m "feat: trigger servicing from two core events

state_machine.quote.state_changed filtered to entering open or
change_requested, plus quote_comment.written filtered to inserts. Both are core
classes and core event names, so ADR 0001's untyped SwagCommercial surface stays
confined to the bridge.

quote.requested needs no subscription of its own: a buyer request runs through
the customer_send transition (draft to open), verified against the shop's
state_machine_transition table.

in_review and replied are deliberately outside the trigger set. They are the
states the agent's own servicing drives, so a self-trigger is impossible by
construction rather than only by the context stamp."
```

---

### Task 10: Wire it into the container

**Files:**
- Modify: `src/Resources/config/services.php` (append inside the `CommercialAvailability::isAvailableByClass()` guard, after the `QuoteGatewayInterface` registration)
- Test: `tests/Integration/ServicingWiringTest.php` (create)

**Interfaces:**
- Consumes: every class from Tasks 5–9.
- Produces: `QuoteServicingTrigger` registered as an event subscriber; `ServiceQuoteHandler` registered as a message handler; `QuoteServicingLock` available with the merchant's `LOCK_DSN`.

- [ ] **Step 1: Write the failing wiring test**

This is the only thing that catches a `services.php` mistake, and it is newly possible: the plugin is installed and active in `merchant-quote-shop` as of #8, so the container really contains these services.

Create `tests/Integration/ServicingWiringTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;
use MerchantQuoteAgentPlugin\Servicing\Data\ServicingTriggerReason;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingLock;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingTrigger;
use MerchantQuoteAgentPlugin\Servicing\ServiceQuoteHandler;
use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface as ComponentDispatcher;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The trigger and handler are wired by services.php, which no unit test can
 * reach. This is possible at all because the plugin is installed and active in
 * this shop (#8) — before that, services.php never loaded here.
 */
final class ServicingWiringTest extends IntegrationTestCase
{
    public function testTheTriggerIsRegisteredForBothEventNames(): void
    {
        $dispatcher = static::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        self::assertInstanceOf(ComponentDispatcher::class, $dispatcher);

        foreach (array_keys(QuoteServicingTrigger::getSubscribedEvents()) as $eventName) {
            self::assertTrue(
                $dispatcher->hasListeners($eventName),
                sprintf('Nothing listens to "%s"; the servicing trigger is not wired.', $eventName),
            );

            $classes = array_map(
                static fn(array|object $listener): string => \is_array($listener) && \is_object($listener[0])
                    ? $listener[0]::class
                    : $listener::class,
                $dispatcher->getListeners($eventName),
            );

            self::assertContains(QuoteServicingTrigger::class, $classes, sprintf(
                'The servicing trigger is not among the listeners for "%s".',
                $eventName,
            ));
        }
    }

    public function testTheHandlerAndLockResolve(): void
    {
        $handler = static::getContainer()->get(ServiceQuoteHandler::class);
        self::assertInstanceOf(ServiceQuoteHandler::class, $handler);

        $locks = static::getContainer()->get(QuoteServicingLock::class);
        self::assertInstanceOf(QuoteServicingLock::class, $locks);
        self::assertTrue($locks->for('wiring-probe')->acquire(), 'The wired lock factory cannot acquire.');
    }

    /**
     * Implementing AsyncMessageInterface is the entire routing configuration.
     * If Shopware ever stops routing that interface to `async`, servicing
     * silently becomes synchronous — worth an assertion rather than a comment.
     */
    public function testTheMessageIsRoutedAsynchronously(): void
    {
        self::assertInstanceOf(
            AsyncMessageInterface::class,
            ServiceQuoteMessage::because('probe', ServicingTriggerReason::StateEntered),
        );

        $routing = static::getContainer()->getParameter('messenger.routing');
        self::assertIsArray($routing);
        self::assertArrayHasKey(AsyncMessageInterface::class, $routing);
    }
}
```

If `messenger.routing` is not a readable container parameter in this Shopware build, replace that last assertion by dispatching the message on the real bus and asserting a row lands in `messenger_messages` with `queue_name = 'async'` — but check the parameter first, it is cheaper.

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer run test:integration -- --filter ServicingWiringTest`

Expected: FAIL — the services do not resolve.

- [ ] **Step 3: Register the services**

Append to `src/Resources/config/services.php`, **inside** the `if (!CommercialAvailability::isAvailableByClass()) { return; }` guard, after the existing `QuoteGatewayInterface` registration:

```php
    // Servicing (issue #4): trigger, queue and lock. Inside the guard because
    // a shop without SwagCommercial has no quotes to service.
    //
    // LOCK_DSN is read as an injected parameter rather than through getenv():
    // shopware/core defines `env(LOCK_DSN): 'flock'` in its own framework.yaml,
    // so this resolves on every shop whether or not the merchant set it.
    $services->set(QuoteServicingLock::class)->args([
        service('lock.factory'),
        '%env(LOCK_DSN)%',
        service('logger')->ignoreOnInvalid(),
    ]);

    // autoconfigure() picks up EventSubscriberInterface, so no explicit tag.
    $services->set(QuoteServicingTrigger::class)->args([service('messenger.default_bus')]);

    // The gateway argument is the null-returning factory registered above and
    // the pipeline is #18's, registered nowhere yet — both ignoreOnInvalid()
    // so an absent or unlicensed backend degrades to a log line rather than a
    // container error. autoconfigure() picks up #[AsMessageHandler].
    $services->set(ServiceQuoteHandler::class)->args([
        service(QuoteServicingLock::class),
        service('logger'),
        service(QuoteGatewayInterface::class)->ignoreOnInvalid(),
        service(QuoteServicingPipelineInterface::class)->ignoreOnInvalid(),
    ]);
```

Add these imports at the top of the file, keeping the existing alphabetical grouping:

```php
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingLock;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingPipelineInterface;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingTrigger;
use MerchantQuoteAgentPlugin\Servicing\ServiceQuoteHandler;
```

If Task 1 Step 3 took the fallback branch, the third argument becomes `service(QuoteGatewayLocator::class)` (non-nullable, no `ignoreOnInvalid()`), and `QuoteGatewayLocator` is registered above it.

- [ ] **Step 4: Sync and run the test to verify it passes**

`composer run test:integration` syncs this checkout into the container before running, so no separate sync step is needed. The container caches its compiled container, so clear it first:

Run: `docker exec merchant-quote-shop php8.3 bin/console cache:clear`
Then: `composer run test:integration -- --filter ServicingWiringTest`

Expected: PASS, 3 tests.

- [ ] **Step 5: Verify the whole integration suite still boots**

Run: `composer run test:integration`

Expected: every test passes. New services in the container can break the *build* of unrelated services, so this run is about the container compiling, not just about servicing.

- [ ] **Step 6: Run the full quality gate**

Run: `composer run quality`

Expected: clean, including `quality:depcheck` — Tasks 5–9 now import all four packages declared in Task 2, so the "unused dependency" findings noted there must be gone. If `psr/log` still reads as unused, check that `QuoteServicingLock` and `ServiceQuoteHandler` import `Psr\Log\LoggerInterface` rather than a Symfony logger class.

- [ ] **Step 7: Commit**

```bash
git add src/Resources/config/services.php tests/Integration/ServicingWiringTest.php
git commit -m "feat: wire the servicing trigger, handler and lock

Inside the SwagCommercial guard: a shop without it has no quotes to service.
LOCK_DSN arrives as an injected parameter rather than through getenv(), which
works on every shop because core defines a default for it in its own
framework.yaml.

ServicingWiringTest is the only thing that can catch a services.php mistake,
and it is possible at all because the plugin is installed and active in this
shop as of #8."
```

---

### Task 11: Prove the behaviour against the live shop

Three properties that only a real shop can demonstrate: own writes do not re-trigger, a replayed delivery during a pass hands off once, and the crash budget parks a poison quote.

**Files:**
- Test: `tests/Integration/ServicingTriggerTest.php` (create)
- Test: `tests/Integration/ServicingReentrancyTest.php` (create)
- Test: `tests/Integration/ServicingCrashBudgetTest.php` (create)

**Interfaces:**
- Consumes: everything from Tasks 3–10.
- Produces: nothing. This task is the acceptance gate for issue #4's "Done when" list.

- [ ] **Step 1: Write the trigger suppression test**

Create `tests/Integration/ServicingTriggerTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingTrigger;
use Shopware\Core\Framework\Context;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The trigger against real Shopware events, with a collecting bus in place of
 * the real one — the same hand-built-collaborator approach IntegrationTestCase
 * uses for the gateway. What is real here is the events: SwagCommercial's own
 * comment write and the core state machine, not a constructed event object.
 */
final class ServicingTriggerTest extends IntegrationTestCase
{
    /**
     * Issue #4's "the agent's own comment does not re-trigger servicing", on
     * the real write path. This is the test that fails if the Context stamp
     * ever stops surviving QuoteCommenter's scope().
     */
    public function testAnAgentCommentDoesNotQueueTheQuote(): void
    {
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $bus = self::collectingBus();

        $this->withTrigger($bus, static function () use ($quoteId): void {
            static::gateway()->addComment($quoteId, 'ServicingTriggerTest agent reply');
        });

        self::assertSame([], $bus->messages, 'The agent re-triggered itself by writing a comment.');
    }

    /**
     * `in_review` is where the agent's own `process` transition lands, so it
     * must not queue anything — otherwise claiming a quote queues it again.
     */
    public function testTheAgentsOwnProcessTransitionDoesNotQueueTheQuote(): void
    {
        $quoteId = QuoteFixture::quoteInState(static::getContainer(), Context::createDefaultContext(), 'open');
        $bus = self::collectingBus();

        $this->withTrigger($bus, static function () use ($quoteId): void {
            static::gateway()->transition($quoteId, QuoteTransition::Process);
        });

        self::assertSame([], $bus->messages);
    }

    private function withTrigger(object $bus, callable $write): void
    {
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $dispatcher = static::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(\Symfony\Component\EventDispatcher\EventDispatcherInterface::class, $dispatcher);

        $trigger = new QuoteServicingTrigger($bus);
        $dispatcher->addSubscriber($trigger);

        try {
            $write();
        } finally {
            $dispatcher->removeSubscriber($trigger);
        }
    }

    /** @return MessageBusInterface&object{messages: list<ServiceQuoteMessage>} */
    private static function collectingBus(): object
    {
        return new class implements MessageBusInterface {
            /** @var list<ServiceQuoteMessage> */
            public array $messages = [];

            /**
             * @param object|Envelope $message
             * @param array<array-key, \Symfony\Component\Messenger\Stamp\StampInterface> $stamps
             */
            #[\Override]
            public function dispatch($message, array $stamps = []): Envelope
            {
                // Assert::, not self:: — inside an anonymous class self:: is the
                // anonymous class, which has no assertion methods.
                \PHPUnit\Framework\Assert::assertInstanceOf(ServiceQuoteMessage::class, $message);
                $this->messages[] = $message;

                return new Envelope($message);
            }
        };
    }
}
```

The container's real `QuoteServicingTrigger` is also subscribed and will dispatch onto the real bus during these tests. That is harmless — the transaction rolls back, so no `messenger_messages` row survives — but it means these assertions are about the hand-built trigger's collecting bus, not about the absence of all dispatches.

- [ ] **Step 2: Add the fixture helper the second test needs**

Append to `tests/Integration/QuoteFixture.php`, matching its existing style:

```php
    /**
     * A quote currently in a given state, so a test can drive a transition
     * that state actually offers. Separate from anyQuoteId() because the
     * quote.state machine only offers `process` from open or change_requested.
     *
     * @throws \RuntimeException when the shop has no quote in that state
     */
    public static function quoteInState(ContainerInterface $container, Context $context, string $state): string
    {
        $repository = $container->get('quote.repository');

        if (!$repository instanceof EntityRepository) {
            throw new \RuntimeException('quote.repository is not an EntityRepository.');
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('stateMachineState.technicalName', $state));
        $criteria->addSorting(new FieldSorting('quoteNumber'));
        $criteria->setLimit(1);

        $id = $repository->searchIds($criteria, $context)->firstId();

        if ($id === null) {
            throw new \RuntimeException(sprintf(
                'No quote in state "%s" exists in the shop. Move one there through the admin, or '
                . 'pick a state the seed actually contains.',
                $state,
            ));
        }

        return $id;
    }
```

- [ ] **Step 3: Run the trigger test**

Run: `composer run test:integration -- --filter ServicingTriggerTest`

Expected: PASS, 2 tests. If `testAnAgentCommentDoesNotQueueTheQuote` fails, do not weaken it — the Context stamp is broken and Task 4's `AgentContextTest` should be failing too.

- [ ] **Step 4: Write the re-entrancy regression test**

This is the test issue #4 asks for by name: "a regression test replays a comment event while servicing is in flight."

Create `tests/Integration/ServicingReentrancyTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;
use MerchantQuoteAgentPlugin\Servicing\Data\ServicingTriggerReason;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingLock;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingPipelineInterface;
use MerchantQuoteAgentPlugin\Servicing\ServiceQuoteHandler;
use MerchantQuoteAgentPlugin\Servicing\ServicingFingerprint;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Context;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Issue #4's headline guarantee, against the live shop: duplicate or concurrent
 * triggers for one quote produce exactly one servicing pass, and the quote is
 * not left mid-flight.
 */
final class ServicingReentrancyTest extends IntegrationTestCase
{
    public function testASecondDeliveryDuringAPassHandsOffOnlyOnce(): void
    {
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $gateway = static::gateway();
        $locks = new QuoteServicingLock(new LockFactory(new InMemoryStore()), 'redis://x');
        $message = ServiceQuoteMessage::because($quoteId, ServicingTriggerReason::CommentWritten);

        // The pipeline writes a real agent comment — the write that used to
        // re-fire the webhook indistinguishably from a buyer's — and then
        // replays the same delivery from inside the pass.
        $pipeline = new class($locks) implements QuoteServicingPipelineInterface {
            public int $passes = 0;
            public ?ServiceQuoteMessage $replay = null;
            public ?ServiceQuoteHandler $handler = null;
            public bool $replayThrew = false;

            public function __construct(private readonly QuoteServicingLock $locks) {}

            #[\Override]
            public function service(QuoteSnapshot $snapshot, QuoteGatewayInterface $gateway): void
            {
                ++$this->passes;
                $gateway->addComment($snapshot->identity->quoteId, 'ServicingReentrancyTest agent reply');

                if ($this->replay === null || $this->handler === null) {
                    return;
                }

                // Re-entrant delivery, lock still held by the outer pass.
                try {
                    ($this->handler)($this->replay);
                } catch (\Throwable) {
                    $this->replayThrew = true;
                }
            }
        };

        $handler = new ServiceQuoteHandler($locks, new NullLogger(), $gateway, $pipeline);
        $pipeline->handler = $handler;
        $pipeline->replay = $message;

        $handler($message);

        self::assertSame(1, $pipeline->passes, 'A replayed delivery produced a second servicing pass.');
        self::assertTrue(
            $pipeline->replayThrew,
            'The re-entrant delivery should have been refused for retry, not silently dropped: '
            . 'dropping it would drop a buyer comment that landed during the pass.',
        );
    }

    public function testAFreshDeliveryAfterAPassIsANoOp(): void
    {
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $gateway = static::gateway();
        $locks = new QuoteServicingLock(new LockFactory(new InMemoryStore()), 'redis://x');
        $message = ServiceQuoteMessage::because($quoteId, ServicingTriggerReason::CommentWritten);

        $pipeline = new class implements QuoteServicingPipelineInterface {
            public int $passes = 0;

            #[\Override]
            public function service(QuoteSnapshot $snapshot, QuoteGatewayInterface $gateway): void
            {
                ++$this->passes;
                $gateway->addComment($snapshot->identity->quoteId, 'ServicingReentrancyTest agent reply');
            }
        };

        $handler = new ServiceQuoteHandler($locks, new NullLogger(), $gateway, $pipeline);

        $handler($message);
        $handler($message);

        self::assertSame(1, $pipeline->passes, 'The duplicate delivery serviced the quote a second time.');

        $marker = ServicingFingerprint::stamped($gateway->fetchSnapshot($quoteId)->lifecycle->customFields);
        self::assertNotNull($marker, 'The successful pass did not stamp the marker.');
        self::assertSame(
            ServicingFingerprint::of($gateway->fetchSnapshot($quoteId)),
            $marker,
            'The stamped marker does not match the quote as it now stands, so the next trigger will '
            . 'service it again for nothing. The agent reply must not move the fingerprint.',
        );
    }
}
```

The second test is the strongest single assertion in this plan: it proves against the real database that an agent comment written by the pipeline leaves the fingerprint unchanged, which is the property the whole dedup design rests on.

- [ ] **Step 5: Run the re-entrancy test**

Run: `composer run test:integration -- --filter ServicingReentrancyTest`

Expected: PASS, 2 tests.

If the last assertion fails, read the two fingerprints before changing anything. The likely cause is that SwagCommercial has started stamping an author on agent comments, in which case `AddCommentTest` is failing too and the fingerprint's authorship assumption needs revisiting — that is the documented signal, not a test to relax.

- [ ] **Step 6: Write the crash budget test**

Create `tests/Integration/ServicingCrashBudgetTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;
use MerchantQuoteAgentPlugin\Servicing\Data\ServicingTriggerReason;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingLock;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingPipelineInterface;
use MerchantQuoteAgentPlugin\Servicing\ServiceQuoteHandler;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Context;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * The counter that bounds a message which kills the worker. Tested without
 * killing anything: what matters is that the budget lives in the quote's own
 * data rather than in process state, so it is readable and enforceable on the
 * next delivery.
 */
final class ServicingCrashBudgetTest extends IntegrationTestCase
{
    public function testAQuoteAtTheCeilingParksWithoutServicing(): void
    {
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $gateway = static::gateway();

        $gateway->updateQuote($quoteId, new QuoteUpdate(customFields: [
            ServiceQuoteHandler::ATTEMPTS_KEY => ServiceQuoteHandler::MAX_ATTEMPTS,
        ]));

        $pipeline = self::countingPipeline();
        $handler = new ServiceQuoteHandler(self::locks(), new NullLogger(), $gateway, $pipeline);

        $this->expectException(UnrecoverableMessageHandlingException::class);

        try {
            $handler(ServiceQuoteMessage::because($quoteId, ServicingTriggerReason::StateEntered));
        } finally {
            self::assertSame(0, $pipeline->passes, 'A quote past its crash budget was serviced anyway.');
        }
    }

    /**
     * Also proves a customFields value can be cleared through the bridge:
     * QuoteWriter drops an empty array but must pass a null VALUE through, and
     * clearing the counter on success depends on that.
     */
    public function testASuccessfulPassClearsTheCounter(): void
    {
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $gateway = static::gateway();

        $gateway->updateQuote($quoteId, new QuoteUpdate(customFields: [
            ServiceQuoteHandler::ATTEMPTS_KEY => 2,
        ]));

        $handler = new ServiceQuoteHandler(self::locks(), new NullLogger(), $gateway, self::countingPipeline());
        $handler(ServiceQuoteMessage::because($quoteId, ServicingTriggerReason::StateEntered));

        $customFields = $gateway->fetchSnapshot($quoteId)->lifecycle->customFields;
        self::assertNull(
            $customFields[ServiceQuoteHandler::ATTEMPTS_KEY] ?? null,
            'A healthy quote is still carrying a crash counter, so its budget will never reset.',
        );
    }

    public function testTheCounterIsRaisedBeforeTheQuoteIsServiced(): void
    {
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $gateway = static::gateway();

        $pipeline = new class implements QuoteServicingPipelineInterface {
            public ?int $counterDuringPass = null;

            #[\Override]
            public function service(QuoteSnapshot $snapshot, QuoteGatewayInterface $gateway): void
            {
                $customFields = $gateway->fetchSnapshot($snapshot->identity->quoteId)->lifecycle->customFields;
                $counter = $customFields[ServiceQuoteHandler::ATTEMPTS_KEY] ?? null;
                $this->counterDuringPass = \is_int($counter) ? $counter : null;
            }
        };

        $handler = new ServiceQuoteHandler(self::locks(), new NullLogger(), $gateway, $pipeline);
        $handler(ServiceQuoteMessage::because($quoteId, ServicingTriggerReason::StateEntered));

        self::assertSame(
            1,
            $pipeline->counterDuringPass,
            'The crash counter was not committed to the database before the pipeline ran. A segfault '
            . 'during servicing leaves no exception and no retry stamp, so an uncommitted counter '
            . 'means the doctrine transport redelivers the poison message hourly, forever.',
        );
    }

    private static function locks(): QuoteServicingLock
    {
        return new QuoteServicingLock(new LockFactory(new InMemoryStore()), 'redis://x');
    }

    /** @return QuoteServicingPipelineInterface&object{passes: int} */
    private static function countingPipeline(): object
    {
        return new class implements QuoteServicingPipelineInterface {
            public int $passes = 0;

            #[\Override]
            public function service(QuoteSnapshot $snapshot, QuoteGatewayInterface $gateway): void
            {
                ++$this->passes;
            }
        };
    }
}
```

- [ ] **Step 7: Run the crash budget test**

Run: `composer run test:integration -- --filter ServicingCrashBudgetTest`

Expected: PASS, 3 tests.

If `testASuccessfulPassClearsTheCounter` fails with the key still holding an integer, `QuoteWriter` is dropping the null value rather than writing it. Fix it there — the counter must be clearable — and add a `UpdateQuoteTest` case pinning that a null custom-field value round-trips.

- [ ] **Step 8: Run everything**

Run: `composer run test`
Expected: PASS, whole unit suite.

Run: `composer run test:integration`
Expected: PASS, whole integration suite.

Run: `composer run quality`
Expected: clean.

- [ ] **Step 9: Check issue #4's "Done when" list**

Confirm each, and note in the commit body which test covers it:

- Duplicate or concurrent triggers for one quote produce exactly one offer → `ServicingReentrancyTest::testAFreshDeliveryAfterAPassIsANoOp`
- A quote can no longer be left in `in_review` by a race → the lock plus `ServicingReentrancyTest::testASecondDeliveryDuringAPassHandsOffOnlyOnce`
- The agent's own comment does not re-trigger servicing → `ServicingTriggerTest::testAnAgentCommentDoesNotQueueTheQuote`, `AgentContextTest`
- A regression test replays a comment event while servicing is in flight → `ServicingReentrancyTest::testASecondDeliveryDuringAPassHandsOffOnlyOnce`
- A message whose worker dies mid-servicing is retried a bounded number of times, then parked; its lock expires → `ServicingCrashBudgetTest` (all three), plus `QuoteServicingLock::TTL_SECONDS` and `QuoteServicingLockTest`

- [ ] **Step 10: Commit**

```bash
git add tests/Integration/ServicingTriggerTest.php tests/Integration/ServicingReentrancyTest.php \
        tests/Integration/ServicingCrashBudgetTest.php tests/Integration/QuoteFixture.php
git commit -m "test: prove the servicing loop against the live shop

Covers issue #4's Done-when list:

- exactly one pass for duplicate triggers: ServicingReentrancyTest
- no quote left mid-flight by a race: the lock, plus the replay test
- the agent's own comment does not re-trigger: ServicingTriggerTest,
  AgentContextTest
- a comment event replayed while servicing is in flight: the replay test
- bounded crash deliveries, then parked, lock expiring: ServicingCrashBudgetTest
  and QuoteServicingLock::TTL_SECONDS

The strongest single assertion is that the marker stamped after a pass matches
the quote as it then stands: it proves against the real database that an agent
comment leaves the fingerprint unchanged, which is what the whole dedup design
rests on."
```

---

## Notes for the executor

**Order matters in two places.** Task 2 before any task that imports Symfony Messenger, Lock or PSR-3, or `quality:depcheck` fails. Task 4 before Task 9, because the trigger reads `AgentContext::STATE`.

**Integration tests need the container.** `composer run test:integration` syncs this checkout into `merchant-quote-shop` and runs there. If the container is not up, `docker compose -f docker/compose.yaml up -d` and wait for healthy. After changing `services.php`, clear the shop's cache before running, or the compiled container will not see the new services.

**Do not relax a gate to make a task pass.** Complexity, file length and analyse findings are blocking for a reason. Extract a method instead.

**Two tests are load-bearing beyond their own task.** `AgentContextTest` proves the Context state survives SwagCommercial's write path; `ServicingReentrancyTest::testAFreshDeliveryAfterAPassIsANoOp` proves an agent comment does not move the fingerprint. If either fails after a Shopware or SwagCommercial upgrade, the design assumption behind it has changed — read the spec's Risks table before editing the test.
