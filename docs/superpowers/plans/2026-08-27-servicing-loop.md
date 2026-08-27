# Servicing Loop Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement issue #4's in-process quote triggers, asynchronous servicing message, per-quote lock, own-write suppression, and bounded recovery from worker death.

**Architecture:** `src/Servicing/` normalizes SwagCommercial events into a Shopware async message and handles it behind a distributed quote lock. A Shopware DAL entity records delivery starts by message UUID so a process-killed Doctrine Messenger delivery is bounded even though the transport does not add a retry stamp. The handler delegates through a null pipeline seam owned by issue #18.

**Tech Stack:** PHP 8.3, Shopware 6.7 DAL and plugin migrations, Symfony 7.4 Messenger and Lock, PHPUnit 11, Mago, Composer Dependency Analyser.

**Continuation note:** Gemini already created partial, uncommitted production files and tests. Preserve them, add the missing behaviors below, and stage only the exact paths listed for each commit.

**Spec:** `docs/superpowers/specs/2026-08-27-servicing-loop-design.md`

---

## File ownership map

- `src/MerchantQuoteAgentPlugin.php`: canonical agent context-state and exact comment-row identifiers.
- `src/Bridge/SwagCommercialQuoteGateway.php`: stamps every agent write context; the comment event path persists the resulting row identity.
- `src/Bridge/Data/QuoteIdentity.php`, `src/Bridge/QuoteSnapshotReader.php`: carry the sales-channel id needed by the async message.
- `src/Servicing/Data/ServiceQuoteMessage.php`: serializable queue contract with stable message UUID.
- `src/Servicing/Attempt/`: Shopware DAL definition, entity, collection, and repository-backed attempt store.
- `src/Migration/Migration1787849556CreateServicingAttempt.php`: owns the attempt-ledger schema.
- `src/Servicing/QuoteServicingHandler.php`: gateway guard, quote lock, durable delivery bound, revision/state guards, and pipeline delegation.
- `src/Servicing/QuoteServicingSubscriber.php` plus focused resolver/filter helpers: trigger normalization and own-write filtering.
- `src/Resources/config/services.php`: entity definition, generated repository, pipeline, handler, and subscriber wiring.
- `tests/Unit/Servicing/`: fast message, handler, filter, and subscriber behavior.
- `tests/Integration/`: live Shopware DAL, bridge provenance, event suppression, lock, revision, and durable-attempt verification.

---

### Task 1: Adopt the async contract and pipeline seam

**Files:**
- Modify: `composer.json`
- Modify: `composer.lock`
- Create/adopt: `src/Servicing/Data/ServiceQuoteMessage.php`
- Create/adopt: `src/Servicing/QuoteServicingPipelineInterface.php`
- Create/adopt: `src/Servicing/NullQuoteServicingPipeline.php`
- Create/adopt: `tests/Unit/Servicing/Data/ServiceQuoteMessageTest.php`
- Create/adopt: `tests/Unit/Servicing/NullQuoteServicingPipelineTest.php`

- [ ] **Step 1: Make the message-id requirement fail first**

Update the message test to construct with named arguments and assert all four fields:

```php
$message = new ServiceQuoteMessage(
    messageId: '018d5f91e4707a83b2be1f6f624bfd2a',
    quoteId: 'quote-123',
    salesChannelId: 'sales-channel-456',
    revision: $revision,
);

self::assertSame('018d5f91e4707a83b2be1f6f624bfd2a', $message->messageId);
self::assertSame('quote-123', $message->quoteId);
self::assertSame('sales-channel-456', $message->salesChannelId);
self::assertSame($revision, $message->revision);
```

- [ ] **Step 2: Run the focused test and verify RED**

Run: `vendor/bin/phpunit tests/Unit/Servicing/Data/ServiceQuoteMessageTest.php`

Expected: FAIL because `ServiceQuoteMessage::__construct()` has no `messageId` parameter.

- [ ] **Step 3: Add the stable message id to the queue contract**

Use this constructor in `ServiceQuoteMessage`:

```php
public function __construct(
    public string $messageId,
    public string $quoteId,
    public string $salesChannelId,
    public QuoteRevision $revision,
) {}
```

Keep `AsyncMessageInterface`; keep the pipeline signature exactly:

```php
public function service(QuoteSnapshot $snapshot, QuoteGatewayInterface $gateway): void;
```

- [ ] **Step 4: Declare the direct packages the servicing source imports**

Add these sorted requirements to `composer.json`:

```json
"psr/log": "^3.0",
"symfony/event-dispatcher": "^7.4",
"symfony/lock": "^7.4",
"symfony/messenger": "^7.4"
```

Run:

```bash
composer update psr/log symfony/event-dispatcher symfony/lock symfony/messenger --with-all-dependencies
```

Expected: dependency resolution succeeds on PHP 8.3 and updates only the declared package graph.

- [ ] **Step 5: Verify the adopted slice**

Run:

```bash
vendor/bin/phpunit tests/Unit/Servicing/Data/ServiceQuoteMessageTest.php tests/Unit/Servicing/NullQuoteServicingPipelineTest.php
composer run format:check
```

Expected: 2 tests pass; formatting passes.

- [ ] **Step 6: Commit the slice**

```bash
git add -- composer.json composer.lock src/Servicing/Data/ServiceQuoteMessage.php src/Servicing/QuoteServicingPipelineInterface.php src/Servicing/NullQuoteServicingPipeline.php tests/Unit/Servicing/Data/ServiceQuoteMessageTest.php tests/Unit/Servicing/NullQuoteServicingPipelineTest.php
git commit -m "feat: define asynchronous quote servicing message"
```

---

### Task 2: Add the Shopware-native durable attempt ledger

**Files:**
- Modify: `composer.json`
- Modify: `composer.lock`
- Create: `src/Migration/Migration1787849556CreateServicingAttempt.php`
- Create: `src/Servicing/Attempt/ServicingAttemptDefinition.php`
- Create: `src/Servicing/Attempt/ServicingAttemptEntity.php`
- Create: `src/Servicing/Attempt/ServicingAttemptCollection.php`
- Create: `src/Servicing/Attempt/ServicingAttemptStoreInterface.php`
- Create: `src/Servicing/Attempt/DalServicingAttemptStore.php`
- Create: `tests/Integration/ServicingAttemptStoreTest.php`

- [ ] **Step 1: Write the DAL store integration test**

The test resolves `merchant_quote_agent_servicing_attempt.repository`, constructs `DalServicingAttemptStore`, and proves the persistent lifecycle:

```php
$messageId = Uuid::randomHex();
$store = new DalServicingAttemptStore($repository);

self::assertSame(1, $store->recordDelivery($messageId));
self::assertSame(2, $store->recordDelivery($messageId));
$store->completeDelivery($messageId);
self::assertSame(1, $store->recordDelivery($messageId));
```

Use the test transaction cleanup already supplied by `IntegrationTestCase`.

- [ ] **Step 2: Run the focused integration test and verify RED**

Run: `composer run test:integration -- --filter ServicingAttemptStoreTest`

Expected: FAIL because the DAL entity/repository and store do not exist.

- [ ] **Step 3: Add the plugin migration**

Create `Migration1787849556CreateServicingAttempt` extending `MigrationStep`. `update()` executes exactly one idempotent table creation:

```sql
CREATE TABLE IF NOT EXISTS `merchant_quote_agent_servicing_attempt` (
    `id` BINARY(16) NOT NULL,
    `attempt_count` INT UNSIGNED NOT NULL,
    `created_at` DATETIME(3) NOT NULL,
    `updated_at` DATETIME(3) NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

`getCreationTimestamp()` returns `1787849556`; `updateDestructive()` is empty.

- [ ] **Step 4: Define the Shopware DAL entity**

`ServicingAttemptDefinition` uses `ENTITY_NAME = 'merchant_quote_agent_servicing_attempt'` and this field collection:

```php
return new FieldCollection([
    (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
    (new IntField('attempt_count', 'attemptCount'))->addFlags(new Required()),
    new CreatedAtField(),
    new UpdatedAtField(),
]);
```

`ServicingAttemptEntity` uses `EntityIdTrait` and exposes typed `getAttemptCount()` / `setAttemptCount(int $attemptCount)`. `ServicingAttemptCollection` extends `EntityCollection<ServicingAttemptEntity>`, returns the entity class from `getExpectedClass()`, and uses API alias `merchant_quote_agent_servicing_attempt_collection`.

- [ ] **Step 5: Implement the repository-backed store**

The interface is:

```php
interface ServicingAttemptStoreInterface
{
    public function recordDelivery(string $messageId): int;
    public function completeDelivery(string $messageId): void;
}
```

`DalServicingAttemptStore` receives only `EntityRepository $repository`. Each public operation creates a fresh `Context::createDefaultContext()`. `recordDelivery()` searches `Criteria([$messageId])`, reads the typed entity through `getEntities()->get($messageId)`, increments from zero, and calls:

```php
$this->repository->upsert([[
    'id' => $messageId,
    'attemptCount' => $attemptCount,
]], $context);
```

`completeDelivery()` calls `delete([['id' => $messageId]], Context::createDefaultContext())`. The handler's quote lock serializes this read/upsert pair.

- [ ] **Step 6: Declare DBAL because the migration imports it directly**

Add `"doctrine/dbal": "^4.3"` to `require`, then run:

```bash
composer update doctrine/dbal --with-all-dependencies
```

Expected: Composer retains a Shopware-compatible DBAL 4 release.

- [ ] **Step 7: Install/refresh the plugin and verify GREEN**

Run:

```bash
scripts/sync-to-shop.sh
docker exec -w /var/www/html merchant-quote-shop php8.3 bin/console database:migrate MerchantQuoteAgentPlugin --all
composer run test:integration -- --filter ServicingAttemptStoreTest
```

Expected: migration reports success or no pending work; the store test passes.

- [ ] **Step 8: Commit the persistence slice**

```bash
git add -- composer.json composer.lock src/Migration/Migration1787849556CreateServicingAttempt.php src/Servicing/Attempt/ServicingAttemptDefinition.php src/Servicing/Attempt/ServicingAttemptEntity.php src/Servicing/Attempt/ServicingAttemptCollection.php src/Servicing/Attempt/ServicingAttemptStoreInterface.php src/Servicing/Attempt/DalServicingAttemptStore.php tests/Integration/ServicingAttemptStoreTest.php
git commit -m "feat: persist quote servicing delivery attempts"
```

---

### Task 3: Stamp all agent writes and persist exact comment-row provenance

**Files:**
- Modify: `src/MerchantQuoteAgentPlugin.php`
- Modify: `src/Bridge/SwagCommercialQuoteGateway.php`
- Create: `src/Servicing/QuoteCommentProvenancePromoter.php`
- Create: `src/Servicing/QuoteCommentWriteResultInspector.php`
- Delete after updating consumers: `src/Servicing/ServicingConstants.php`
- Modify: `tests/Integration/AddCommentTest.php`
- Modify: `tests/Integration/ServicingSubscriberTest.php`
- Modify: `tests/Unit/Servicing/QuoteServicingSubscriberTest.php`

- [ ] **Step 1: Write the live provenance regression test**

Register the actual subscriber method as a temporary listener for `quote_comment.written`, use a mock bus that expects no dispatch, call `$gateway->addComment($quoteId, $text)`, then always remove the listener in `finally`. Capture the live event's string `EntityWriteResult` primary key and assert both discriminators:

```php
self::assertSame(
    $commentId,
    $gateway->fetchSnapshot($quoteId)->lifecycle->customFields[MerchantQuoteAgentPlugin::LAST_AGENT_COMMENT_ID] ?? null,
);
self::assertTrue($eventContextHadAgentState);
```

- [ ] **Step 2: Run the regression and verify RED**

Run: `composer run test:integration -- --filter 'AgentComment.*Suppresses|Comment.*Provenance'`

Expected: FAIL because the gateway currently uses an unmarked default context and does not persist the exact comment-row id.

- [ ] **Step 3: Define the canonical identifiers**

Add to `MerchantQuoteAgentPlugin`:

```php
public const CONTEXT_STATE_AGENT_SERVICING = 'merchant_quote_agent_servicing';
public const LAST_AGENT_COMMENT_ID = 'quote_agent_last_comment_id';
```

Update subscriber/filter/tests to use these root constants, then delete `ServicingConstants.php` so there is one owner.

- [ ] **Step 4: Centralize agent contexts in the gateway**

Add:

```php
private static function createAgentContext(): Context
{
    $context = Context::createDefaultContext();
    $context->addState(MerchantQuoteAgentPlugin::CONTEXT_STATE_AGENT_SERVICING);

    return $context;
}
```

Use it for every write-side gateway operation: `updateLineItems`, `addProduct`, `recalculate`, `updateQuote`, `addComment`, and `transition`. Keep reads on a plain default context.

- [ ] **Step 5: Promote the generated live comment id**

Inside `addComment()`, retain the existence read and call `QuoteCommentWriterInterface::comment()` with the agent context. Do not pre-stamp text. In `QuoteCommentEventDispatcher`, ignore update/delete results and non-live snapshot projection results; Shopware emits a projection before the live insert for the same comment id. For each live agent-context insert with non-empty string quote and comment ids, persist the generated id and never dispatch:

```php
$gateway->updateQuote(
    $quoteId,
    new QuoteUpdate(customFields: [MerchantQuoteAgentPlugin::LAST_AGENT_COMMENT_ID => $commentId]),
);
```

For an ordinary/replayed event, pass the write-result primary key to `QuoteCommentFilter`: suppress only an author-less row whose id exactly matches the persisted id. Authored rows and distinct author-less rows remain eligible even when their text is identical. A quote write cannot recurse because the subscriber does not subscribe to `quote.written`.

- [ ] **Step 6: Verify bridge and servicing regressions**

Run:

```bash
composer run test:integration -- --filter 'AddCommentTest|ServicingSubscriberTest'
vendor/bin/phpunit tests/Unit/Servicing/QuoteServicingSubscriberTest.php
```

Expected: the live agent insert persists its exact row id without dispatch; update/delete results are inert; replay of that id is suppressed; buyer, staff, and different-id author-less inserts still dispatch. Duplicate eligible rows for one quote still produce one message.

- [ ] **Step 7: Commit the provenance slice**

```bash
git add -- src/MerchantQuoteAgentPlugin.php src/Bridge/SwagCommercialQuoteGateway.php src/Servicing/QuoteCommentProvenancePromoter.php src/Servicing/QuoteCommentWriteResultInspector.php src/Servicing/ServicingConstants.php tests/Integration/AddCommentTest.php tests/Integration/ServicingSubscriberTest.php tests/Unit/Servicing/QuoteServicingSubscriberTest.php
git commit -m "fix: mark quote agent writes for reentrancy suppression"
```

---

### Task 4: Complete the locked handler and crash bound

**Files:**
- Create/adopt: `src/Servicing/Exception/QuoteServicingException.php`
- Create/adopt: `src/Servicing/Exception/QuoteServicingUnavailableException.php`
- Create: `src/Servicing/Exception/QuoteServicingBusyException.php`
- Create: `src/Servicing/Exception/QuoteServicingAttemptsExhaustedException.php`
- Create/adopt: `src/Servicing/QuoteServicingHandler.php`
- Create/adopt: `tests/Unit/Servicing/QuoteServicingHandlerTest.php`

- [ ] **Step 1: Extend the handler tests with durable-attempt behavior**

Mock `ServicingAttemptStoreInterface` and add these cases:

```php
$attempts->expects(self::once())->method('recordDelivery')->with($message->messageId)->willReturn(1);
$attempts->expects(self::once())->method('completeDelivery')->with($message->messageId);
```

For pipeline failure, assert `completeDelivery()` is never called and the lock is released. For a generic snapshot-fetch failure, assert the same retention and propagation. For `QuoteNotFoundException`, assert the deleted quote is terminally handled by completing the row, returning without the pipeline, and releasing the lock. For exhaustion, return `5`, assert the pipeline is never called, and expect `QuoteServicingAttemptsExhaustedException` implementing `UnrecoverableExceptionInterface`.

For lock contention, assert the handler throws `QuoteServicingBusyException` implementing `RecoverableExceptionInterface`, reports a 5,000 ms retry delay, never records or completes an attempt, and does not release the lock owned by the other worker. Retrying instead of acknowledging the message preserves a newer buyer update that the in-flight pass may not contain; recording only after lock acquisition keeps contention outside the durable pipeline-attempt budget.

- [ ] **Step 2: Run the handler suite and verify RED**

Run: `vendor/bin/phpunit tests/Unit/Servicing/QuoteServicingHandlerTest.php`

Expected: FAIL because the handler has no attempt-store dependency or exhausted-attempt exception.

- [ ] **Step 3: Make unavailable/exhausted failures unrecoverable**

Both concrete exceptions extend `QuoteServicingException` and implement `Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface`. The exhausted factory message includes only `messageId`, `quoteId`, and the numeric delivery count.

- [ ] **Step 4: Integrate the store behind the quote lock**

Use constants:

```php
private const LOCK_TTL_SECONDS = 300.0;
private const MAX_PIPELINE_DELIVERIES = 4;
```

Attempt the lock non-blocking. If acquisition fails, log the contention and throw `QuoteServicingBusyException` with a 5,000 ms retry delay before calling the attempt store. After acquiring the lock, call `recordDelivery()`. When the count is greater than four, log a warning and throw the exhausted exception before fetching the quote or calling the pipeline. On `QuoteNotFoundException`, stale revision, inactive state, and successful pipeline completion, call `completeDelivery()` before returning. Do not complete the row when any other fetch failure or pipeline execution throws. Always release an acquired lock in `finally`.

Keep exactly five constructor dependencies: `LockFactory`, `QuoteServicingPipelineInterface`, `LoggerInterface`, `?QuoteGatewayInterface`, and `ServicingAttemptStoreInterface`.

- [ ] **Step 5: Verify handler GREEN and static analysis**

Run:

```bash
vendor/bin/phpunit tests/Unit/Servicing/QuoteServicingHandlerTest.php
composer run lint
composer run typecheck
```

Expected: handler tests pass; the previous subscriber errors may remain until Task 5, but no new handler error appears.

- [ ] **Step 6: Commit the handler slice**

```bash
git add -- src/Servicing/Exception/QuoteServicingException.php src/Servicing/Exception/QuoteServicingUnavailableException.php src/Servicing/Exception/QuoteServicingBusyException.php src/Servicing/Exception/QuoteServicingAttemptsExhaustedException.php src/Servicing/QuoteServicingHandler.php tests/Unit/Servicing/QuoteServicingHandlerTest.php
git commit -m "feat: bound locked quote servicing deliveries"
```

---

### Task 5: Complete event normalization, including `quote.requested`

**Files:**
- Create/adopt: `src/Servicing/QuoteServicingSubscriber.php`
- Create/adopt: `src/Servicing/QuoteCommentEventDispatcher.php`
- Create/adopt: `src/Servicing/QuoteCommentFilter.php`
- Create/adopt: `src/Servicing/QuoteStateEventResolver.php`
- Modify/adopt: `tests/Unit/Servicing/QuoteServicingSubscriberTest.php`

- [ ] **Step 1: Add the missing event and UUID assertions**

Assert `getSubscribedEvents()` contains:

```php
'quote.requested' => 'onQuoteStateEnter',
'state_enter.quote.state.open' => 'onQuoteStateEnter',
'state_enter.quote.state.in_review' => 'onQuoteStateEnter',
'state_enter.quote.state.change_requested' => 'onQuoteStateEnter',
'quote_comment.written' => 'onQuoteCommentWritten',
```

For every dispatched message, assert `Uuid::isValid($message->messageId)` and that quote, sales channel, and revision match the fetched snapshot. Add one test with a `quote.requested`-shaped object exposing `getQuoteId()` and `getContext()`.

- [ ] **Step 2: Run the subscriber suite and verify RED**

Run: `vendor/bin/phpunit tests/Unit/Servicing/QuoteServicingSubscriberTest.php`

Expected: FAIL because `quote.requested` and generated message UUIDs are absent.

- [ ] **Step 3: Normalize all four trigger families**

Add `quote.requested` to the subscription map. Centralize message creation so both state and comment paths dispatch:

```php
new ServiceQuoteMessage(
    Uuid::randomHex(),
    $quoteId,
    $snapshot->identity->salesChannelId,
    $snapshot->revision,
)
```

Keep comment-event per-quote deduplication and the serviceable-state check.

- [ ] **Step 4: Satisfy strict exception and payload handling**

Add `@throws Symfony\Component\Messenger\Exception\ExceptionInterface` to dispatching methods. Remove the unused subscriber logger dependency. Narrow payload fields with `isset()` / `is_string()` before assignment; do not cast `mixed` values.

- [ ] **Step 5: Verify subscriber GREEN and typecheck GREEN**

Run:

```bash
vendor/bin/phpunit tests/Unit/Servicing/QuoteServicingSubscriberTest.php
composer run typecheck
```

Expected: subscriber tests pass and Mago reports zero errors (existing non-blocking warnings may remain as the repository baseline).

- [ ] **Step 6: Commit the subscriber slice**

```bash
git add -- src/Servicing/QuoteServicingSubscriber.php src/Servicing/QuoteCommentEventDispatcher.php src/Servicing/QuoteCommentFilter.php src/Servicing/QuoteStateEventResolver.php tests/Unit/Servicing/QuoteServicingSubscriberTest.php
git commit -m "feat: normalize quote servicing triggers"
```

---

### Task 6: Wire the bridge identity, DAL repository, handler, and subscriber

**Files:**
- Modify/adopt: `src/Bridge/Data/QuoteIdentity.php`
- Modify/adopt: `src/Bridge/QuoteSnapshotReader.php`
- Modify/adopt: `src/Resources/config/services.php`
- Modify: `tests/Integration/GatewayWiringTest.php`

- [ ] **Step 1: Add wiring and identity assertions**

Assert a live snapshot has a non-empty `identity->salesChannelId`. In the installed-shop path, assert the container resolves `ServicingAttemptDefinition`, `ServicingAttemptStoreInterface`, `QuoteServicingHandler`, and `QuoteServicingSubscriber`.

- [ ] **Step 2: Run focused integration and verify RED**

Run: `composer run test:integration -- --filter GatewayWiringTest`

Expected: FAIL until the new definition/store/handler/subscriber graph compiles.

- [ ] **Step 3: Make sales-channel identity required**

Use this non-defaulted constructor tail:

```php
public string $currencyIso,
public string $salesChannelId,
```

Keep `QuoteSnapshotReader`'s runtime normalization:

```php
salesChannelId: (string) ($quote->get('salesChannelId') ?? ''),
```

and fail the integration assertion if the live quote produces an empty value.

- [ ] **Step 4: Register services in dependency order**

Register `ServicingAttemptDefinition` before the Commercial availability check so Shopware always builds its repository. Replace the early return with a conditional bridge-registration block and keep a `$gatewayReference` variable that is `null` when Commercial classes are absent and a nullable `QuoteGatewayInterface` service reference when they are present. This lets already-queued messages reach the handler and park loudly after Commercial is removed.

After that conditional block, always register:

```php
$services->set(DalServicingAttemptStore::class)->args([
    service(ServicingAttemptDefinition::ENTITY_NAME . '.repository'),
]);
$services->alias(ServicingAttemptStoreInterface::class, DalServicingAttemptStore::class);
```

The store creates its own default Shopware context. Register the null pipeline alias. Register the handler with lock factory, pipeline, logger, `$gatewayReference`, and attempt store. Register the subscriber with only `messenger.default_bus` and `$gatewayReference`; autoconfiguration supplies the subscriber and message-handler tags.

- [ ] **Step 5: Verify the compiled graph**

Run:

```bash
scripts/sync-to-shop.sh
docker exec -w /var/www/html merchant-quote-shop php8.3 bin/console cache:clear
docker exec -w /var/www/html merchant-quote-shop php8.3 bin/console debug:container MerchantQuoteAgentPlugin\\Servicing\\QuoteServicingHandler --no-ansi
composer run test:integration -- --filter GatewayWiringTest
```

Expected: container compiles, handler resolves, and wiring tests pass.

- [ ] **Step 6: Commit the wiring slice**

```bash
git add -- src/Bridge/Data/QuoteIdentity.php src/Bridge/QuoteSnapshotReader.php src/Resources/config/services.php tests/Integration/GatewayWiringTest.php
git commit -m "feat: wire the quote servicing runtime"
```

---

### Task 7: Prove concurrency, stale delivery, reentrancy, and crash exhaustion

**Files:**
- Create/adopt: `tests/Integration/ServicingSubscriberTest.php`
- Create/adopt: `tests/Integration/ServicingConcurrencyTest.php`

- [ ] **Step 1: Update all messages and handlers to the final signatures**

Every integration message receives `Uuid::randomHex()` first. Every handler receives the real `ServicingAttemptStoreInterface` after the gateway. Keep the existing nested second handler that runs while the first holds `quote_servicing_<quoteId>`; catch its recoverable busy exception and prove it has a 5,000 ms retry delay and no attempt-ledger row.

- [ ] **Step 2: Add the durable crash-exhaustion regression**

Create one message UUID, call the real store's `recordDelivery($messageId)` four times to model four processes dying after recording and before acknowledgment, then invoke the handler with that same message. Assert:

```php
$this->expectException(QuoteServicingAttemptsExhaustedException::class);
$pipeline->expects(self::never())->method('service');
```

After the assertion, delete the attempt row in `finally` so the test is isolated even outside transaction rollback.

- [ ] **Step 3: Run integration tests and verify GREEN**

Run:

```bash
composer run test:integration -- --filter 'ServicingSubscriberTest|ServicingConcurrencyTest|ServicingAttemptStoreTest'
```

Expected: the in-flight handler executes the pipeline once while the concurrent delivery is explicitly retried without consuming an attempt; stale/replayed deliveries do not mutate; agent comments do not dispatch; the fifth crash delivery is unrecoverable before pipeline execution.

- [ ] **Step 4: Verify Shopware's native queue posture**

Run:

```bash
docker exec -w /var/www/html merchant-quote-shop php8.3 bin/console debug:config framework messenger --no-ansi
```

Expected output includes `failure_transport: failed`, async routing for `Shopware\\Core\\Framework\\MessageQueue\\AsyncMessageInterface`, and `max_retries: 3`.

- [ ] **Step 5: Commit the integration slice**

```bash
git add -- tests/Integration/ServicingSubscriberTest.php tests/Integration/ServicingConcurrencyTest.php
git commit -m "test: prove quote servicing concurrency recovery"
```

---

### Task 8: Run the ACL quality gate and final review

**Files:**
- Modify only files implicated by a failing in-scope check.

- [ ] **Step 1: Run the required project checks**

```bash
composer run format:check
composer run lint
composer run typecheck
composer run quality:depcheck
composer run quality
composer run test
composer run test:integration
composer run quality:maintainability
```

Expected: all blocking commands exit 0. Maintainability is advisory; record its output without weakening thresholds.

- [ ] **Step 2: Read the final diff against project conventions**

Verify manually: strict types in every file; no raw debug output; no secrets/PII in structured logs; caught/wrapped exceptions preserve `$previous`; the DAL schema/migration/store stay together under Servicing ownership; no direct SwagCommercial imports escaped `src/Bridge/`; no repeated identifier literals; no file exceeds the gate.

- [ ] **Step 3: Inspect the complete branch diff**

Run:

```bash
git status --short --branch
git diff --check origin/main...HEAD
git diff --stat origin/main...HEAD
git log --oneline --decorate origin/main..HEAD
```

Expected: only issue #4 design, code, migration, tests, and dependency changes are present; no uncommitted files remain.

- [ ] **Step 4: Commit any verification-only fixes by exact path**

Stage only the confirmed fix paths and commit them with `fix: satisfy servicing loop quality gate`. Skip this commit when verification required no changes.
