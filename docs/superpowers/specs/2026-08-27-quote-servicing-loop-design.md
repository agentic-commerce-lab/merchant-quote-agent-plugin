# Servicing loop: trigger, queue and per-quote lock (issue #4)

**Status:** approved design, 2026-08-27. Implements issue #4 and replaces the
webhook path described in the "Execution model" section of
`../../2026-08-25-quote-agent-shopware-plugin-design.md`. The servicing pipeline
itself and the LLM client are **#18** and are out of scope here — this spec ends
at a typed seam #18 fills in.

## Why

The TypeScript path reacts to quotes over an HTTP webhook, and three of its
failures are structural rather than incidental:

- **Gap #1, re-entrancy.** The agent's own `addComment` re-fires
  `quote_comment.written`, and over an HTTP boundary that delivery is
  indistinguishable from a buyer comment. LLM latency widens the window, so a
  second delivery races the first against SwagCommercial's quote-version
  management. Observed locally: a quote left stuck in `in_review`, and the
  colliding write surfacing as a 500 on the `quote_history` foreign-key insert.
- **Gap #12, delivery.** Webhook delivery has to be verified per
  Shopware/SwagCommercial build, with a Flow Builder fallback to document.
- **Gap #14, single instance.** Deduplication is an in-process `inFlight` map in
  `quote-webhook.ts`, correct only while exactly one instance runs.

In-plugin, all three collapse. There is no delivery to verify, own writes are
recognisable in-process, and deduplication can be a real lock. What replaces the
webhook is: two in-process listeners, one async message, one lock per quote, and
one idempotency marker on the quote.

## What we verified before designing

Everything below was read out of `merchant-quote-shop` (Shopware `dev-trunk`,
SwagCommercial 7.13.1, Agentic Commerce 1.2.0) or out of this repository, not
assumed.

- **The plugin is installed and active in the test shop.**
  `plugin:list` reports `MerchantQuoteAgentPlugin 1.0.0, Installed: Yes,
  Active: Yes`. So `src/Resources/config/services.php` loads, and a subscriber
  or message handler registered there is reachable in the integration suite.
  `IntegrationTestCase`'s "the plugin is deliberately not installed" comment
  and the same claim in `2026-08-26-shopware-quote-bridge-design.md` are stale
  as of #8 — the hand-built gateway graph still works, but it is no longer the
  only option.
- **The quote state machine's servicing entry states are `open` and
  `change_requested`, and both are reached by real transitions.** From the
  shop's `state_machine_transition` table for `quote.state`:
  `customer_send: draft → open`, `request_change: replied → change_requested`,
  `process: open|change_requested → in_review`, `sent: in_review|open → replied`.
  Because a buyer's quote request goes through `ACTION_CUSTOMER_SEND`
  (`QuoteSendRequestRoute:96`) rather than being created directly in `open`, a
  state-transition subscription covers `quote.requested` as well.
- **Core dispatches a quote-scoped state-change event under a stable name.**
  `StateMachineRegistry::dispatchTransitionEvents()` dispatches
  `StateMachineStateChangeEvent` twice per transition (leave, then enter) under
  `getName()` = `'state_machine.' . stateMachine.technicalName . '_changed'`, i.e.
  **`state_machine.quote.state_changed`**. SwagCommercial's own
  `QuoteStateChangeEventListener` subscribes to exactly that string. Both the
  class and the name are core, so this needs no SwagCommercial type.
- **The comment-level `customFields` discriminator issue #4 names is not
  reachable.** `QuoteCommenter::comment()` builds the `quote_comment` row
  itself from a fixed key list, accepts no custom fields, and returns `void` —
  there is no id to follow up with. Stamping a comment would take a second write
  plus a heuristic re-read to find the row we just created.
- **`Context` is state-aware and the state survives the write path.**
  `Shopware\Core\Framework\Context` uses `StateAwareTrait`
  (`addState`/`hasState`/`getStates`). `Context::scope()` strips only states
  that a previous `scope()` call pushed (`$this->scopeStates`), so a state added
  outside any scope survives `QuoteCommenter`'s
  `scope(Context::CRUD_API_SCOPE, …)`. The DAL passes the same `Context`
  instance into the written event, so `hasState()` is readable there.
- **`Context::createWithVersionId()` silently drops states, and SwagCommercial
  mirrors every comment into the snapshot lane.** Discovered while implementing
  the context stamp, then verified in the vendor source:
  `Context::createWithVersionId()` (`Framework/Context.php:173`) builds a
  `new self(...)` and re-applies only `scope` and `extensions` — `states` is
  never carried. SwagCommercial's `QuoteHistoryWriter::trackComment()` →
  `createSnapshotQuoteComments()` re-versions the context that way to mirror the
  comment into `SNAPSHOT_VERSION_ID`. So **one `addComment()` fires two
  `quote_comment.written` events for the same comment id**: the live-lane insert,
  which carries the stamp, and the snapshot mirror, which has lost it. Measured
  on one call: `versionId=019cfaaf…(snapshot) hasState=false`,
  `versionId=0fa91ce3…(live) hasState=true`. `writeQuoteChanges()` uses the same
  pattern for sync-tracked quote fields, so this is a general property of
  Shopware's snapshot mirroring rather than a comment-specific quirk.

  Two consequences, and the second is the one the first draft of this design
  missed entirely. The stamp alone cannot suppress the agent's own comment,
  because the unstamped mirror still reaches the trigger. And a **buyer's**
  comment fires the same two events, so a trigger keyed only on the event would
  queue two messages for one ask — harmless in outcome, since the lock and
  fingerprint collapse it to one pass, but half the queue traffic is waste.
  Both are fixed by the same one-line filter: see "Own writes" below.
- **Retry and a dead-letter path already exist.** `framework.messenger` routes
  `Shopware\Core\Framework\MessageQueue\AsyncMessageInterface` to the `async`
  transport, whose retry strategy is `max_retries: 3, delay: 1000,
  multiplier: 2, jitter: 0.1`, with `failure_transport: failed`. No transport
  configuration is needed from this plugin.
- **Messenger's retry budget does not bound a killed worker.**
  `SendFailedMessageForRetryListener` reads and writes the retry count on a
  `RedeliveryStamp`, and it runs on `WorkerMessageFailedEvent` — that is, only
  when a handler *throws*. A SIGKILL or a segfault emits no event and adds no
  stamp. Symfony's doctrine transport then reclaims the row once
  `redeliver_timeout` has passed (default **3600s**,
  `doctrine-messenger/Transport/Connection.php:56`) and redelivers the original
  envelope with retry count 0. So `max_retries: 3` bounds thrown failures and
  nothing else: a message that kills the worker loops hourly, forever, and never
  reaches `failed`. This is the one failure mode #4 has to build for rather than
  inherit, and it is exactly the shape of the known `addProduct` segfault.
- **Core's `DeduplicatableMessageInterface` is not usable.** It exists
  (`Framework/MessageQueue/DeduplicatableMessageInterface.php`) but is
  `@experimental stableVersion:v6.8.0 feature:DEDUPLICATABLE_MESSAGES`, and its
  own docblock says deduplication "has to be implemented in the
  middleware/transport" — the only middleware shipped is
  `RoutingOverwriteMiddleware`. Revisit on 6.8.
- **`MESSENGER_TRANSPORT_DSN` defaults to `doctrine://default?auto_setup=false`**
  (`shopware/core` `Framework/Resources/config/packages/framework.yaml:3`), and
  this shop leaves it at the default. So the message row is written on the same
  connection as the triggering entity write and becomes visible to a worker only
  after that transaction commits. See Risks for what changes on redis/amqp.
- **`LOCK_DSN=flock`** in this shop's `.env`, and `config/packages/lock.yaml`
  feeds it into `framework.lock`. `lock.factory` resolves (a private alias for
  `lock.default.factory`). `flock` is a host-local store.
- **Agent comments are author-less on all three fields.** From #3, measured:
  `QuoteCommenter` under `Context::createDefaultContext()` (a `SystemSource`)
  leaves `createdById`, `customerId` and `employeeId` all null, and 42 of this
  shop's 118 existing comments are author-less on all three, while 76 carry an
  author (4 `createdById`, 72 `customerId`, 0 `employeeId`). `AddCommentTest`
  asserts the null authorship, so it fails the day SwagCommercial starts
  stamping an author.
- **The revision precondition is not a compare-and-set.** From #3: only
  `updatedAt` moves (`versionId` identifies the live/snapshot lane, not a
  per-quote version), and `QuoteRevisionMismatch` detects a stale read — the
  re-read and the write are separate statements with no `FOR UPDATE`. The lock
  is what serialises writers.
- **`addProduct` on a variant product segfaults the PHP process** (exit 139, no
  exception — #3, guarded by `VariantRejectingProductAdder`). A servicing
  failure can therefore be a dropped worker rather than a thrown error.

## Decision

### Module shape

Eight new files, seven edits. Trigger and handler both live in `src/Servicing/`,
which is where #18 lands the pipeline, so the seam does not move later.

```
src/Servicing/QuoteServicingPipelineInterface.php  NEW  the #18 seam
src/Servicing/Data/ServiceQuoteMessage.php         NEW  AsyncMessageInterface; quoteId + trigger reason
src/Servicing/Data/ServicingTriggerReason.php      NEW  StateEntered | CommentWritten, for log triage
src/Servicing/ServiceQuoteHandler.php     NEW  AsMessageHandler: lock → fingerprint → hand off → stamp
src/Servicing/ServicingFingerprint.php    NEW  pure: of(QuoteSnapshot): string
src/Servicing/QuoteServicingLock.php      NEW  LockFactory wrapper: key, TTL, local-store warning
src/Servicing/QuoteServicingTrigger.php   NEW  one subscriber, two listener methods

src/Bridge/AgentContext.php               NEW  stamped Context factory + the state constant
src/Bridge/SwagCommercialQuoteGateway.php EDIT seven inline createDefaultContext() → AgentContext::create()
src/Bridge/Data/QuoteIdentity.php         EDIT + salesChannelId
src/Bridge/Data/QuoteComment.php          EDIT + employeeId, + isAuthored()
src/Bridge/QuoteCommentMapper.php         EDIT map employeeId
src/Bridge/QuoteSnapshotReader.php        EDIT read salesChannelId into the identity
src/Resources/config/services.php         EDIT register the six new services
composer.json                             EDIT symfony/lock, symfony/messenger, symfony/event-dispatcher, psr/log
```

One subscriber class rather than two: both listeners normalise their event into
the same message and dispatch it. Splitting them would duplicate that.

### Trigger: two core events, one message

`QuoteServicingTrigger` implements `EventSubscriberInterface` and subscribes to
two names, both of which carry core types only:

| Event name | Event class | Filter |
| --- | --- | --- |
| `state_machine.quote.state_changed` | `Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent` | context version is `Defaults::LIVE_VERSION`; `getTransitionSide() === STATE_MACHINE_TRANSITION_SIDE_ENTER`; and `getStateName()` in `{open, change_requested}`; quote id from `getTransition()->getEntityId()` |
| `quote_comment.written` | `Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent` | context version is `Defaults::LIVE_VERSION`; then write results with `getOperation() === OPERATION_INSERT` whose payload carries a `quoteId`; deduplicated within the event |

Both produce `ServiceQuoteMessage(quoteId, reason)` on the default bus.

Two consequences of subscribing to the *state machine* rather than to
SwagCommercial's `state_enter.quote.state.*`:

- **No new `@internal` SwagCommercial surface.** ADR 0001 confines untyped
  commercial access to the bridge's adapters. `QuoteStateMachineStateChangeEvent`
  and `QuoteRequestedEvent` are both `@internal` in SwagCommercial and would have
  to be reached as `object` with a `@mago-expect` suppression, for no
  information we cannot get from core.
- **The state filter is a whitelist, not a wildcard.** Symfony's dispatcher
  cannot subscribe to `state_enter.quote.state.*` anyway, and the whitelist is
  the precision the design doc wanted from state transitions in the first place:
  `in_review` and `replied` — the states *our own* servicing drives — are simply
  not in it.

**The message carries only what it needs.** `quoteId` plus a `reason` enum
(`StateEntered`, `CommentWritten`) for log and dead-letter triage. Not the
sales-channel id and not a revision marker: the handler re-reads the snapshot
regardless, and the snapshot is a better source than a serialised copy that may
already be stale. `salesChannelId` moves onto `QuoteIdentity` so #18 can select a
per-sales-channel policy from the snapshot it is handed.

### Own writes: one context state, not a comment stamp

`Bridge\AgentContext`:

```php
final class AgentContext
{
    public const STATE = 'merchant-quote-agent';

    public static function create(): Context
    {
        $context = Context::createDefaultContext();
        $context->addState(self::STATE);

        return $context;
    }
}
```

`SwagCommercialQuoteGateway` replaces its seven inline
`Context::createDefaultContext()` calls with `AgentContext::create()`.

**Two filters, not one.** The stamp is the second of them, and on its own it is
not enough — `Context::createWithVersionId()` drops states, so SwagCommercial's
snapshot mirror of a comment arrives unstamped (see the finding above). Both
listeners therefore open with:

```php
// A snapshot-lane write is a MIRROR of a live write, never an independent
// buyer action, and the re-versioned context has lost every state — including
// ours. Filtering to the live lane drops the mirror and, with it, the
// duplicate message a single buyer comment would otherwise produce.
if ($event->getContext()->getVersionId() !== Defaults::LIVE_VERSION) {
    return;
}

if ($event->getContext()->hasState(AgentContext::STATE)) {
    return;
}
```

`Defaults::LIVE_VERSION` is a core constant, so this adds no SwagCommercial
surface. The version filter is correct independently of the stamp: servicing
decisions belong to the live quote. The stamp is what then suppresses the
agent's own *live* write.

This is one check covering comments, transitions *and* line-item writes, where
the comment-level `customFields` stamp issue #4 names would cover only comments
and is not reachable through `QuoteCommenter::comment()` at all. Its ceiling is
that it works in-process — which is exactly where our own writes happen, since
the message handler and the subscriber run in the same worker.

`AgentContext` lives in `Bridge` rather than `Servicing` because the layer order
is policy, bridge, servicing (design doc, "Port order"): the bridge stamps, and
servicing reads the stamp.

### Handler: lock, then fingerprint, then hand off

```
ServiceQuoteHandler::__invoke(ServiceQuoteMessage $message): void
    gateway === null → warning('gateway unavailable, quote not serviced'), return
    lock = locks->for(message->quoteId)                    # ttl 300s
    if !lock->acquire() → throw RecoverableMessageHandlingException
    try
        snapshot = gateway->fetchSnapshot(message->quoteId)
        current  = ServicingFingerprint::of(snapshot)
        if current === snapshot->lifecycle->customFields[MARKER_KEY]
            debug('nothing new since the last pass'), return
        if pipeline === null
            warning('quote claimed but no servicing implementation registered (#18)'), return

        attempts = (int) snapshot->lifecycle->customFields[ATTEMPTS_KEY]
        if attempts >= MAX_ATTEMPTS                                     # 4
            error('quote servicing killed the worker repeatedly, parking', …)
            throw UnrecoverableMessageHandlingException
        gateway->updateQuote(quoteId, new QuoteUpdate(customFields: [ATTEMPTS_KEY => attempts + 1]))

        pipeline->service(snapshot, gateway)

        after = gateway->fetchSnapshot(message->quoteId)          # for the STATE only
        gateway->updateQuote(quoteId, new QuoteUpdate(customFields: [
            # Comment components from the snapshot we SERVICED; state from the
            # fresh read. See "The stamp describes what was consumed" below —
            # stamping of(after) wholesale silently swallows a buyer comment
            # that landed mid-pass.
            MARKER_KEY   => ServicingFingerprint::stamp(snapshot, after->lifecycle->stateTechnicalName),
            ATTEMPTS_KEY => null,
        ]))
    finally
        lock->release()
```

Inside the `try`, `QuoteNotFoundException` is caught and logged as a warning
rather than rethrown: a quote deleted between trigger and handling is not a
failure worth retrying. Every other throwable propagates, so Messenger's retry
and dead-letter path see it.

**`?QuoteGatewayInterface`.** `QuoteGatewayFactory::create()` returns null when
SwagCommercial is unlicensed (#3), so the handler types the gateway nullable and
a null is a loud log line and a completed message, never a silent no-op.

**`?QuoteServicingPipelineInterface`.** The seam #18 fills in:

```php
interface QuoteServicingPipelineInterface
{
    public function service(QuoteSnapshot $snapshot, QuoteGatewayInterface $gateway): void;
}
```

The gateway is a parameter rather than a #18 constructor dependency on purpose:
the handler has already established that it is non-null and licensed, so handing
it over means #18 cannot end up holding a second, differently-resolved instance —
or a null one.

#4 registers no implementation, so the handler's pipeline collaborator is null:
it logs and returns *before* stamping the marker, because nothing was serviced.
#18 registers the real implementation and no other file changes. This mirrors the
nullable-collaborator pattern `services.php` already uses and ships no
placeholder class that exists only to be deleted.

**Contention.** A non-blocking `acquire()` that returned would reopen the
dropped-comment hole below, so a held lock throws
`Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException` and
Messenger's existing backoff (1s, 2s, 4s, then `failed`) does the waiting. No
worker blocks and no transport configuration is added. The cost is bounded and
visible: a servicing pass slower than roughly seven seconds *and* a genuinely
concurrent trigger parks one redundant message in `failed`. Harmless, because the
first pass did the work and the fingerprint makes the parked message a no-op if
it is ever replayed.

### The marker is a fingerprint, not a revision

Issue #4 specifies a revision marker carried on the message, compared on re-read.
That does not work here, for two independent reasons.

**A revision marker re-triggers itself.** `updatedAt` is the only field that
moves (#3), and our own servicing moves it — line prices, discount, expiration,
state, and the marker write itself. Any marker containing `updatedAt` differs
from the freshly-read value on the next pass, so every duplicate trigger looks
like new work.

**Revision-abort drops real asks.** Handler 1 services and writes; handler 2
re-reads, sees a moved revision, and aborts. If what moved was handler 1's own
write, that is correct. If a buyer commented during the servicing window, handler
2 has just discarded a genuine ask, and nothing will trigger again.

So the marker is a fingerprint of *what was serviced*, persisted on the quote:

```
ServicingFingerprint::of($snapshot) = implode('|', [
    $snapshot->lifecycle->stateTechnicalName,
    count(authored comments),
    max(createdAt of authored comments) formatted 'U.u', or '0',
])

authored = createdById !== null || customerId !== null || employeeId !== null
```

Stored on the quote's `customFields` under `merchant_quote_agent_serviced`, a
constant on `ServicingFingerprint` (`MARKER_KEY`) so the key that computes the
value and the key that stores it cannot drift apart. A missing key compares as
"no previous pass", never as a match. The crash counter below
(`ATTEMPTS_KEY = merchant_quote_agent_attempts`, and `MAX_ATTEMPTS`) is a
`ServiceQuoteHandler` concern and lives there, not on the fingerprint.

Why this holds:

- **Our own reply cannot move the comment half.** Agent comments are author-less
  on all three fields — measured in #3 and pinned by `AddCommentTest`, which
  fails the day SwagCommercial starts stamping an author. The 42 pre-existing
  author-less comments are historical and static, so they never change a
  fingerprint either.
- **A buyer comment always moves it**, wherever in the servicing window it
  lands, because it is authored and it changes both the count and the maximum.
  This is the hole revision-abort leaves open.
- **The state half does move on our writes** (`open → in_review → replied`),
  so the state component has to come from a fresh read after servicing. The
  comment components must NOT — see below.
- **Escalation does not loop.** Escalating leaves the state where it is and adds
  no authored comment, so the fingerprint is stamped and the quote stays quiet
  until a human or a buyer moves it.
- **The marker write does not re-trigger.** `updateQuote` fires neither a state
  transition nor a comment insert, and the trigger subscribes to nothing else.

#### The stamp describes what was consumed, not what exists afterwards

The first draft of this design stamped `ServicingFingerprint::of($after)` — the
whole fingerprint recomputed from a post-servicing read. That is wrong, and
wrong in exactly the way that made revision-abort unacceptable.

Walk it through. A buyer comments at T1, which queues a message. The handler
reads a snapshot whose authored comments are `[T1]` and begins servicing. During
the pass — LLM latency is seconds — the buyer comments again at T2, queuing a
second message. The agent then writes its own reply (author-less, so invisible to
the comment components) and transitions the quote to `replied`. The post-servicing
read now contains `[T1, T2]`, so `of($after)` is `replied|2|T2`. That gets
stamped. The second message arrives, computes `replied|2|T2`, finds it equal to
the stamp, and returns. **The buyer's second ask is never serviced**, and nothing
anywhere logs a problem.

The stamp has to describe the buyer input the pass actually consumed:

```
ServicingFingerprint::stamp($serviced, $stateAfter) =
    $stateAfter | count(authored in $serviced) | max(authored createdAt in $serviced)
```

Comment components from the pre-servicing snapshot, state from the fresh read.
Re-walk the same scenario: the stamp becomes `replied|1|T1`, the second message
computes `replied|2|T2`, they differ, and T2 gets serviced. A genuine duplicate
with no new input still computes `replied|1|T1` and still returns. An escalation
that changes no state and adds no authored comment still stamps `open|1|T1` and
stays quiet.

`of()` remains the function that asks "what does this quote look like now"; the
handler compares that against the stamp. `stamp()` is the function that answers
"what had we serviced when we finished". They differ only in where the state
component comes from, which is precisely the asymmetry the agent's own writes
create.

`QuoteUpdate.customFields` is shallow-merged per top-level key (#3), so
`merchant_quote_agent_serviced` sits beside the A2CN act chain without touching
it. The key is not registered as a Shopware custom field, so it is invisible in
the admin until #6 registers the escalation fields; that is a display gap, not a
correctness one. No `?QuoteRevision $expected` is passed on the marker write: we
hold the lock, and the precondition is not a compare-and-set anyway.

### Lock

`QuoteServicingLock` wraps the container's `lock.factory`:

- Key `merchant-quote-agent.quote.<quoteId>` — one lock per quote id.
- TTL **300s**, so a segfaulted worker's lock expires instead of wedging the
  quote permanently. Not auto-refreshed: a pass that outlives the TTL is a
  problem to see in the logs, not to paper over.
- On first use, if the underlying store is `FlockStore` or `SemaphoreStore`, log
  one warning naming the risk — those are host-local, so a merchant running
  workers on more than one node needs `LOCK_DSN` pointed at a shared store. The
  plugin does not override the merchant's lock configuration; Shopware's own
  posture is that a clustered shop configures a shared `LOCK_DSN`, and silently
  substituting our own store would diverge from everything else in the shop.

### Worker death

A thrown failure needs nothing from us: Messenger stamps it, retries three times
with backoff, and parks it in `failed`. A **killed** worker needs a counter,
because it accrues no stamp (see the finding above) and would otherwise be
redelivered hourly forever.

The counter is one more key in the write we already do, not a new store:

- `merchant_quote_agent_attempts` on the quote's `customFields`, incremented
  under the lock **before** the pipeline runs, so the increment is committed
  even if the next statement takes the process down.
- Cleared in the same write that stamps the fingerprint, so a healthy quote
  never carries one.
- At `MAX_ATTEMPTS = 4` — the initial delivery plus Shopware's three configured
  retries, so a crash budget and a throw budget are the same size — the handler
  throws `UnrecoverableMessageHandlingException` **before** handing off, which
  parks the message in `failed` without running the pipeline again.
- The counter lives on the quote rather than on the message id: a segfault is a
  property of the quote's data (the variant line item that triggers it), so
  keying it by quote also bounds the *next* message for the same quote instead of
  letting each fresh trigger start a new budget.

Everything else is inherited:

- Exit 139 means the message is never acked, so the transport redelivers it.
- Redelivery is idempotent: the fingerprint either differs (the pass never
  finished, so it should run again) or matches (it finished, so return).
- The lock TTL expires the dead worker's lock.

**Why not a dedicated store.** The obvious alternative is a DAL entity for the
counter — definition, collection, entity, store, interface and a plugin migration,
keyed by message id. That is roughly 230 lines and a table we would then own, for
a single integer, when the write that stamps the fingerprint is already happening
under the same lock. Keying by message id also resets the budget on every fresh
trigger for the same quote, which is the opposite of what a poison quote needs.

### Dependencies

`symfony/lock`, `symfony/messenger`, `symfony/event-dispatcher` and `psr/log` are
all present through `shopware/core` but not declared, and `quality:depcheck`
reports an undeclared use as a shadow dependency. Add all four to `require` at
`^7.4` (`psr/log` at `^3.0`), matching the constraints `shopware/core` resolves
(`symfony/lock ~7.4.0`, `symfony/messenger ~7.4.12`).

## Testing

**Unit** (`tests/Unit/Servicing/`), no shop required:

- `ServicingFingerprintTest` — the properties the design rests on, as tests: an
  author-less comment appended does not change the fingerprint; an authored
  comment does; a state change does; identical snapshots agree.
- `QuoteServicingTriggerTest` — hand-built `StateMachineStateChangeEvent`s:
  enter `open` and enter `change_requested` dispatch once each; leave-side events
  and enter `in_review` / `replied` dispatch nothing; a context carrying
  `AgentContext::STATE` dispatches nothing. Hand-built `EntityWrittenEvent`s:
  an insert with a `quoteId` dispatches once, an update does not, two inserts on
  one quote dispatch once.
- `ServiceQuoteHandlerTest` — fake gateway, fake lock, counting pipeline fake:
  a matching fingerprint hands off zero times; a differing one hands off once and
  stamps; a failed acquire throws `RecoverableMessageHandlingException`; a null
  gateway and a null servicing each return without stamping; the lock is released
  on the exception path. Crash counter: the increment is written *before* the
  hand-off (assert write order, since that ordering is the whole mechanism); a
  successful pass clears it; a quote already at `MAX_ATTEMPTS` throws
  `UnrecoverableMessageHandlingException` and hands off zero times.

**Integration** (`tests/Integration/`, against `merchant-quote-shop`, each test in
a rolled-back transaction):

- `ServicingTriggerTest` — a real `gateway()->addComment(...)` produces no
  message (own-write suppression on the live write path, not a mock of it); a
  real `gateway()->transition($id, Process)` produces none (`in_review` is not a
  trigger state); a real transition into `open` produces exactly one. The
  subscriber is hand-built with a collecting `MessageBusInterface`, the way
  `IntegrationTestCase` already hand-builds the gateway graph.
- `ServicingWiringTest` — the container registers the subscriber for both event
  names and the handler for `ServiceQuoteMessage`, and `ServiceQuoteMessage`
  routes to the `async` transport. This is newly possible because the plugin is
  installed in this shop, and it is the only thing that catches a `services.php`
  mistake.
- `ServicingReentrancyTest` — the regression test issue #4 asks for. A fake
  pipeline writes a real agent comment and then invokes the handler
  again with an identical message, replaying a delivery while the first pass is
  in flight. Asserts exactly one hand-off, that the quote is not left in
  `in_review`, and that the marker is stamped once.

- `ServicingCrashBudgetTest` — the counter against the real shop, without killing
  a worker: write `merchant_quote_agent_attempts` to `MAX_ATTEMPTS` through the
  gateway, then hand the handler a message and assert it parks
  (`UnrecoverableMessageHandlingException`) with the pipeline never invoked, and
  that a pass below the budget clears the key. This is what proves the counter
  survives as quote state rather than as process state.

Not tested, deliberately: the 300s lock TTL expiring (a unit assertion that
`createLock` is called with the TTL is the whole testable surface) and a real
`messenger:consume` worker segfaulting mid-pass. Both are documented rather than
automated — the crash *budget* is tested above, the crash itself is not.

## Risks and what verifies them

| Risk | Verified by |
| --- | --- |
| **Symfony may reject a null-returning factory.** `QuoteGatewayInterface` is registered with `factory([QuoteGatewayFactory, 'create'])`, which returns null on an unlicensed shop. Nothing consumes it today — the handler is the first consumer, so this path has never been exercised in a container. | First task in the plan, before anything is built on it: resolve the handler in the test shop with the license toggle both on and off. If Symfony rejects it, the fallback is a `QuoteGatewayLocator` the handler asks at call time, which changes `services.php` and the handler's constructor and nothing else. |
| **The context stamp is already known to be lost on one path** — `createWithVersionId()` drops states, so SwagCommercial's snapshot mirror arrives unstamped. It could also be lost if a future Shopware version clones `Context` elsewhere on the write path, or if `scope()`'s state handling changes in 6.8 (the `$states` parameter is `NewOptionalParameter(version: 'v6.8.0')`). | The known path is closed by the live-version filter, and `AgentContextTest` pins BOTH halves: the live event carries the stamp, the snapshot event does not. That second assertion is what fails if Shopware ever starts carrying states through `createWithVersionId()` — at which point the filter is still correct but no longer load-bearing. `ServicingTriggerTest`'s own-write case then proves the combination on the real write path. |
| **A non-doctrine transport reopens an ordering hazard.** On `redis://` or `amqp://` the message is visible immediately, so a worker can read the quote before the triggering transaction commits and conclude "nothing new". The default `doctrine://default` makes this impossible because the message row commits with the write. | Documented, not mitigated. A `DelayStamp` would hedge it at the cost of delaying every pass; the fingerprint means the failure mode is a missed trigger, not a corrupted quote. Revisit if a merchant runs a non-doctrine transport. |
| **Host-local locks.** `LOCK_DSN=flock` gives no cross-node exclusion, which is the same constraint gap #14 named, relocated from the process to the host. | The startup warning in `QuoteServicingLock`, plus a README note. |
| **`AddCommentTest` is load-bearing for the fingerprint.** If SwagCommercial starts stamping an author on agent comments, our own reply becomes "authored" and the fingerprint starts moving on our own writes. | `AddCommentTest` already asserts the null authorship and fails on that change. `ServicingFingerprintTest` asserts the property that depends on it, so the failure is legible from two directions. |
| **The stamp composition is load-bearing and easy to get wrong.** Stamping `of($after)` instead of `stamp($serviced, $stateAfter)` reintroduces revision-abort's dropped-comment hole, and does so silently — every test that only checks "duplicate triggers produce one pass" still passes. | A dedicated integration case: service a quote whose pipeline writes a buyer comment mid-pass, then assert a second delivery DOES service it. That test fails under the wrong composition and passes under the right one, which no duplicate-suppression test does. |
| **A quote at `MAX_ATTEMPTS` stays parked for every future trigger**, not just the message that exhausted the budget, because the counter is keyed by quote. That is the intended behaviour for a quote whose data kills workers, but it means a quote can go permanently unserviced until someone clears the key. | Accepted and deliberate — an hourly segfault loop is worse. The counter is quote state, so it is visible where the escalation surfacing of #6 will already be looking, and the `error` log line names the quote. Clearing it is a `customFields` write, not a database repair. |
| **A servicing pass slower than the retry budget parks a redundant message.** Roughly seven seconds of contention exhausts 3 retries. | Accepted. Loud (`failed` transport) rather than silent, and harmless — the fingerprint makes a replayed duplicate a no-op. |

## Non-goals

- **The servicing pipeline and the LLM client** — snapshot → interpret → propose
  → authorize → apply → verify → reply or escalate. That is #18, behind
  `QuoteServicingPipelineInterface`.
- **Escalation surfacing.** The marker key is written unregistered; registering
  quote custom fields and surfacing the escalation reason in the Quotes list is
  #6.
- **Per-sales-channel policy selection.** `salesChannelId` is added to the
  snapshot for #18 and #5 to use; nothing in #4 reads it.
- **A dedicated transport or retry strategy for quote messages.** The `async`
  transport's existing 3-retry/`failed` configuration is what we use for thrown
  failures; the crash counter covers what it does not. Revisit if the
  redundant-DLQ case above turns out to be common rather than theoretical.
- **A dedicated store for the crash counter.** A DAL entity and migration for a
  single integer, when the same write already carries the fingerprint, is
  schema we would own for no gain.
- **Core's `DeduplicatableMessageInterface`.** Experimental and unimplemented in
  6.7; revisit on 6.8.
- **Auto-refreshing the lock TTL.** A pass outliving 300s is a signal, not a
  condition to accommodate.

## Open questions

None blocking. Two to revisit after #18 lands:

1. **Is the 300s lock TTL right?** It is a guess bounded by "longer than an LLM
   round trip, shorter than a human notices". #18 will produce real pass
   durations; set it from those.
2. **Should `reason` widen?** The trigger reason exists for dead-letter triage.
   If #18's escalation paths want to know *why* a quote was queued in order to
   decide anything, that is a behavioural dependency and the enum needs
   designing rather than extending.
