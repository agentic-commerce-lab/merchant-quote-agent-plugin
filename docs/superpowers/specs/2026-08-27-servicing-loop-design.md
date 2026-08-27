# Servicing loop: state-transition subscriber, async handler, per-quote lock — design spec

*2026-08-27 — design spec. Status: approved. Scope: issue #4 — trigger, queue, and lock
for quote servicing. Replaces the webhook path entirely.*

Related:
- `docs/2026-08-25-quote-agent-shopware-plugin-design.md` — plugin architecture & execution model
- `docs/superpowers/specs/2026-08-26-shopware-quote-bridge-design.md` — Shopware bridge & concurrency findings (#3)
- GitHub Issue #4 — Servicing loop
- GitHub Issue #18 — Negotiation engine & LLM strategy (downstream consumer)

---

## 1. Why

The previous TypeScript implementation relied on external webhooks and an in-memory `inFlight` map to handle quote events. This had severe failure modes:
1. **Re-entrancy loops:** The agent's own `addComment` or quote update re-fired webhooks indistinguishable from buyer actions, causing cascading duplicate negotiations.
2. **Race conditions & stuck quotes:** Concurrent webhook deliveries raced against SwagCommercial's quote versioning, resulting in quotes stuck in `in_review` and database 500 errors on `quote_history` foreign key inserts.
3. **Multi-instance unsafety:** In-memory tracking (`inFlight` map) was only correct when exactly one instance ran.
4. **Dropped worker vulnerability:** Segfaults (such as `addProduct` on variant products in SwagCommercial) dropped the worker process without releasing in-memory state or providing bounded retries.

This design implements an **in-process event subscriber, asynchronous Symfony Messenger dispatch, distributed per-quote lock with TTL, and optimistic concurrency check** inside the Shopware plugin.

---

## 2. Architecture & Execution Flow

```
                      Shopware / SwagCommercial
                                 │
     Events:                     │
     • quote.requested           │
     • state_enter.quote.state.* │
     • quote_comment.written     │
                                 ▼
                   ┌───────────────────────────┐
                   │  QuoteServicingSubscriber │
                   │  • Filter own writes      │
                   │  • Filter non-actionable  │
                   │  • Read quote snapshot    │
                   └─────────────┬─────────────┘
                                 │ Dispatches
                                 ▼
                   ┌───────────────────────────┐
                   │    ServiceQuoteMessage    │
                   │  (AsyncMessageInterface)  │
                   │  • messageId              │
                   │  • quoteId                │
                   │  • salesChannelId         │
                   │  • QuoteRevision          │
                   └─────────────┬─────────────┘
                                 │ Async Transport (doctrine://default)
                                 ▼
                   ┌───────────────────────────┐
                   │   QuoteServicingHandler   │
                   │  (#[AsMessageHandler])    │
                   │                           │
                   │  1. Check Gateway         │
                   │  2. Acquire Lock (TTL)    │
                   │  3. Record Delivery       │
                   │  4. Check Revision        │
                   │  5. Invoke Pipeline       │
                   │  6. Release Lock          │
                   └─────────────┬─────────────┘
                                 │
                                 ▼
                   ┌───────────────────────────┐
                   │ QuoteServicingPipeline    │
                   │ (Issue #18 LLM engine)    │
                   └───────────────────────────┘
```

---

## 3. Detailed Component Design

### 3.1 Trigger & Event Normalization (`QuoteServicingSubscriber`)

The subscriber listens to state machine transitions and quote comments, translating them into an asynchronous `ServiceQuoteMessage`.

#### Subscribed Events
1. `quote.requested` — Buyer submitted a quote request
2. `state_enter.quote.state.open` — Quote entered the open state
3. `state_enter.quote.state.in_review` — Quote entered review state
4. `state_enter.quote.state.change_requested` — Buyer requested change on an existing offer
5. `quote_comment.written` — A comment was inserted on a quote

#### Event Filtering Rules
- **Version filter:** Ignore non-live quote-comment projection writes. Shopware promotes the same comment row through a snapshot version before emitting the live write, and only the live row is an actionable trigger.
- **Context state promotion:** If a live event's `Context` carries `MerchantQuoteAgentPlugin::CONTEXT_STATE_AGENT_SERVICING`, the write originated in the agent's own servicing loop. Persist the valid string `EntityWriteResult` primary key under `quote_agent_last_comment_id` on that quote, then return without dispatching. The nested quote update cannot recurse because the subscriber does not listen to `quote.written`.
- **State transition filter:** Ignore transitions into terminal or inactive states (`replied`, `accepted`, `declined`, `cancelled`, `expired`, `draft`).
- **Comment discriminator filter:**
  - If `customerId` or `employeeId` is present: Valid buyer/merchant staff comment $\rightarrow$ dispatch.
  - If author-less (`createdById`, `customerId`, and `employeeId` are null): Check quote `customFields['quote_agent_last_comment_id']`. If the write result's string primary key exactly matches the recorded agent comment id, skip. Otherwise $\rightarrow$ dispatch, even when the text is identical to an earlier agent comment.

#### Dispatch Payload
The subscriber reads the live `QuoteSnapshot` to extract:
- `messageId` (new Shopware UUID for this trigger)
- `quoteId` (string hex)
- `salesChannelId` (string hex)
- `revision` (`QuoteRevision` carrying `updatedAt` / `createdAt`)

Dispatches a `ServiceQuoteMessage` carrying the message id, quote id, sales-channel id, and revision to Symfony Messenger.

---

### 3.2 Message Contract (`ServiceQuoteMessage`)

```php
namespace MerchantQuoteAgentPlugin\Servicing\Data;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

final readonly class ServiceQuoteMessage implements AsyncMessageInterface
{
    public function __construct(
        public string $messageId,
        public string $quoteId,
        public string $salesChannelId,
        public QuoteRevision $revision,
    ) {}
}
```

By implementing `AsyncMessageInterface`, Shopware automatically routes this message to the asynchronous queue transport (`doctrine://default`).

---

### 3.3 Concurrency Control & Per-Quote Lock

#### Lock Specification
- **Service:** `Symfony\Component\Lock\LockFactory` (aliased to `lock.default.factory`).
- **Key Scheme:** `quote_servicing_<quoteId>`.
- **TTL:** 300.0 seconds (5 minutes). This covers LLM latency (3–15s) while ensuring dead workers do not leave locks permanently acquired.
- **Acquisition Strategy:** Non-blocking (`$lock->acquire(blocking: false)`).
  - If the lock cannot be acquired: Another worker is actively servicing the quote. The handler logs the contention and throws `QuoteServicingBusyException`, a recoverable Messenger exception with a 5,000 ms retry delay. This preserves a newer buyer update that may not be represented by the in-flight pass. The exception is raised before recording a delivery, so lock contention does not consume the message's durable pipeline-attempt budget.
- **Release Strategy:** `try { ... } finally { $lock->release(); }`.

---

### 3.4 Native Attempt Ledger

`merchant_quote_agent_servicing_attempt` is owned by the Servicing module and installed by a Shopware plugin migration. Its DAL definition uses the message UUID as the primary key and stores `attemptCount`, `createdAt`, and `updatedAt`. `ServicingAttemptStore` accesses it through Shopware's generated `merchant_quote_agent_servicing_attempt.repository`; application code contains no scattered SQL. The handler holds the quote lock while reading and upserting the counter, so the DAL read/write pair is serialized for a given message.

### 3.5 Optimistic Concurrency & Revision Check

As verified in the Issue #3 spike:
- `versionId` identifies the quote lane (live vs draft snapshot), **not** the revision.
- Quote modifications bump `updatedAt` (with millisecond precision).
- `QuoteRevisionMismatch` detects a stale read, but is not a database-level compare-and-set. The lock serializes writers, and the revision check ensures the worker does not operate on stale data.

#### Handler Execution Steps
1. **Gateway Availability:** Obtain `?QuoteGatewayInterface` from `QuoteGatewayFactory`. If null (SwagCommercial absent or unlicensed):
   - Log warning.
   - Throw `QuoteServicingUnavailableException`, which implements Messenger's `UnrecoverableExceptionInterface`, to park the message in the failure transport rather than looping.
2. **Acquire Lock:** Attempt non-blocking acquisition. If it fails, throw `QuoteServicingBusyException` with a 5,000 ms retry delay before touching the attempt ledger. Messenger retries the distinct message after the active pass releases the lock, preserving updates that arrived while servicing was in flight without burning a pipeline delivery attempt.
3. **Record Delivery:** Increment the durable Shopware DAL attempt row keyed by `$message->messageId`. If the delivery count exceeds four (the initial delivery plus Shopware's three configured retries), throw an unrecoverable Messenger exception so the transport parks the poison message without invoking the pipeline again.
4. **Fetch Live Snapshot:** `$snapshot = $gateway->fetchSnapshot($message->quoteId);`. If this narrowly throws `QuoteNotFoundException`, the quote was deleted and is no longer actionable: complete the delivery row and return. Other fetch failures propagate and retain the row for bounded retry.
5. **Assert Revision:**
   ```php
   if (!$snapshot->revision->matches($message->revision)) {
       $this->logger->info('Quote revision mismatch: quote was modified since message was queued. Aborting stale servicing pass.', [
           'quoteId' => $message->quoteId,
           'messageRevision' => $message->revision->updatedAt?->format(\DateTimeInterface::RFC3339_EXTENDED),
           'currentRevision' => $snapshot->revision->updatedAt?->format(\DateTimeInterface::RFC3339_EXTENDED),
       ]);
       return;
   }
   ```
6. **State Guard:** Verify current state is actionable (`open`, `in_review`, `change_requested`). If the quote moved to `replied` or `cancelled`, abort.
7. **Delegate to Pipeline:** `$this->pipeline->service($snapshot, $gateway);`
8. **Complete Delivery:** Delete the attempt row after successful, stale, or no-longer-actionable handling. Leave it in place when handling throws or the worker dies.
9. **Release Lock:** inside `finally`.

---

### 3.6 Servicing Pipeline Seam (Bridge to Issue #18)

To decouple triggering/locking (Issue #4) from negotiation logic (Issue #18):

```php
namespace MerchantQuoteAgentPlugin\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;

interface QuoteServicingPipelineInterface
{
    public function service(QuoteSnapshot $snapshot, QuoteGatewayInterface $gateway): void;
}
```

Issue #4 provides `NullQuoteServicingPipeline` as a no-op implementation satisfying the interface and enabling complete unit and integration testing of the triggering, queueing, locking, and error paths.

---

## 4. Re-entrancy & Own-Comment Suppression

### The Authorship Finding (#3)
When `QuoteCommenter` runs via default context, `createdById`, `customerId`, and `employeeId` are all null.

### Discriminator Implementation
1. **Context Flag:**
   Define `MerchantQuoteAgentPlugin::CONTEXT_STATE_AGENT_SERVICING = 'merchant_quote_agent_servicing'`.
   Any write or comment issued by the agent gateway runs in a context containing this state.
2. **Exact Comment-Row Discriminator:**
   Define `MerchantQuoteAgentPlugin::LAST_AGENT_COMMENT_ID = 'quote_agent_last_comment_id'`.
   The gateway writes the comment with the agent-state context but does not guess or pre-stamp its id. On the synchronous live `quote_comment.written` event, the subscriber reads the valid, non-empty string primary key from `EntityWriteResult`, persists that exact id through `updateQuote()`, and never dispatches. Shopware also emits an earlier non-live projection event for the same row; the subscriber ignores that projection so it cannot race the live provenance promotion.
   A later ordinary/replayed author-less event is ignored only when its primary-key id exactly matches the persisted id. Authored comments remain eligible, and a distinct author-less row with identical text dispatches normally.

---

## 5. Failure Posture & Worker Death

- **Segmentation Faults / Worker Drop:** Because `addProduct` on variant products can segfault (guarded by `VariantRejectingProductAdder`), unexpected process deaths must not corrupt the queue.
  - The lock TTL (300s) ensures locks auto-expire.
  - Shopware's Doctrine Messenger transport redelivers a process-killed message without adding a Symfony retry stamp. A Shopware-native DAL entity, `merchant_quote_agent_servicing_attempt`, therefore records the start of each delivery before the pipeline runs.
  - The entity id is the message UUID and its `attemptCount` is incremented while the per-quote lock is held. The row is removed on handled completion and deliberately survives thrown errors and process death.
  - Four pipeline deliveries are allowed, matching Shopware's initial delivery plus its default three retries. A fifth delivery throws an exception implementing Messenger's `UnrecoverableExceptionInterface` before the pipeline runs.
  - Shopware's existing `failure_transport: failed` routes that exhausted message to `messenger.transport.failed` for administrative visibility.
- **Unlicensed Shopware Commercial:** Null gateway logs loudly and parks the message in failure transport.

---

## 6. Testing Strategy

### 6.1 Unit Tests
- `QuoteServicingSubscriberTest`:
  - Dispatches message on `quote.requested` and `state_enter.quote.state.open`, `in_review`, `change_requested`.
  - Ignores `replied`, `accepted`, `declined`, `draft`.
  - Promotes a valid live agent-context comment id without dispatching and ignores non-live snapshot projections.
  - Skips only an author-less replay whose primary key matches `quote_agent_last_comment_id`.
  - Dispatches an authored comment or a distinct author-less row even when its text matches an agent comment.
  - Dispatches message for buyer/staff comments.
- `QuoteServicingHandlerTest`:
  - Acquires lock non-blocking and releases in `finally`.
  - Throws a recoverable busy exception with a 5,000 ms delay if the lock is already held, without recording or completing a delivery attempt.
  - Aborts on revision mismatch.
  - Completes a delivery when the quote was deleted, while retaining the row and propagating other fetch failures.
  - Calls `QuoteServicingPipelineInterface` on valid message.
  - Handles null gateway with warning log and unrecoverable exception.
  - Records delivery before invoking the pipeline, clears it after handled completion, and parks a fifth crash delivery without invoking the pipeline.
- `ServicingAttemptStoreTest`:
  - Persists and increments an attempt row through the Shopware DAL repository.
  - Deletes the row after handled completion.

### 6.2 Integration Tests (in `merchant-quote-shop` Docker Container)
- `ServicingSubscriberIntegrationTest`: State transition in live shop dispatches `ServiceQuoteMessage` on the bus.
- `ServicingHandlerConcurrencyIntegrationTest`:
  - A concurrent trigger receives a recoverable busy exception without an attempt-ledger row; after retrying once the lock is available, the distinct delivery can service its newer revision.
  - Stale revision messages abort cleanly without mutating state.
  - Four simulated worker deaths leave a durable counter; the fifth delivery is parked before pipeline execution.
- `ServicingReentrancyRegressionTest`:
  - Agent comment writes carry the context state and exact persisted discriminator and do not trigger recursive message dispatch.
  - Replaying a comment event while servicing is in flight is handled safely.
