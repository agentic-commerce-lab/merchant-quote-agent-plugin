# Terminal quote outcome on the decision record — design

**Issue:** [#33](https://github.com/agentic-commerce-lab/merchant-quote-agent-plugin/issues/33) (2.4a, split out of #19)
**Date:** 2026-08-31
**Depends on:** #19 (the entity, the columns and the migration, merged in #37)
**Consumers:** #34 (the export carries the outcome), #22 (replay evaluation scores prompt variants against it), #21 (the test round's readout)

## Why this exists

The decision record says what the agent did. It never says whether that worked.
#19's own framing: without the outcome nobody can tell a good offer from a bad
one.

The timing argument is what puts this ahead of the other open V1 issues.
**Outcome data cannot be retrofitted.** A transition that happened while no
subscriber was listening is gone — the quote's current state is not a history,
and nothing anywhere records *when* it reached a terminal state. If the
10,000-negotiation run (#21, due 2026-09-04) happens before this ships, all
10,000 records are outcome-blind permanently. #6, #34 and #39 can all be built
afterwards against data already on disk; this one cannot.

## Scope

Four decisions were taken before design.

1. **Five terminal states, not three.** `accepted`, `declined`, `expired`,
   `cancelled`, `withdrawn`. #33's text names only the first three.
2. **Last word wins.** A later terminal transition overwrites an earlier one on
   the same record.
3. **The detail page shows it.** Two rows on #7's per-quote trail. No list
   column.
4. **No migration.** `terminal_state VARCHAR(64)` and `terminal_at DATETIME(3)`
   already exist, reserved by #19 for exactly this.

**#33 closes on this work.** It carries no clause this design leaves undone.

## What the state machine actually does

Read off the running shop's `state_machine_transition` table, not inferred:

```
replied  → accept          → accepted     (no outgoing transitions at all)
replied  → decline         → declined     → reopen, admin_cancel
replied  → expire          → expired      → reopen, admin_extend_expiration, admin_cancel
replied  → admin_withdraw  → withdrawn    → reopen, admin_resend, admin_cancel
*        → admin_cancel    → cancelled    → reopen
```

Three consequences the design leans on.

**Only `accepted` is graph-terminal.** The other four are ends of a negotiation,
not ends of a graph. "Terminal" here is a business label, and the column stores
what the merchant sees, not what the graph guarantees.

**`reopen` is not a servicing trigger.** `QuoteServicingTrigger::TRIGGER_STATES`
is `['open', 'change_requested']`. A reopened quote is never serviced again and
never produces a fresh record, so whatever record was last is last forever. That
is what makes decision 2 matter: a quote that expires, gets its expiration
extended and is then accepted has one record, and `expired` would be the wrong
final word about an offer that was accepted. Because `accepted` has no outgoing
transitions, nothing can overwrite a success.

**Expiry is a real transition.** SwagCommercial's `UpdateQuoteExpireTaskHandler`
calls `StateMachineRegistry->transition()` with `ACTION_EXPIRE` under
`Context::createCLIContext()`. It fires `state_machine.quote.state_changed` like
any other transition — no separate path, no scheduled-task subscription.

## Approach: an event subscriber, chosen over two alternatives

**Rejected: derive the outcome at read time.** #7's page and #34's export could
join each record to the quote's current `stateMachineState` and write no column
at all. Three independent reasons this fails. The export is the point of #34 —
an anonymized JSONL shared with Shopware cannot carry a join back into the
merchant's live `quote` table, so every exported record would ship outcome-blind
and #22 replays exported records. `terminalAt` is not derivable at any price: a
quote knows what state it is in, never when it got there. And a pruned or
deleted quote takes the outcome with it, which is backwards for an audit trail.

**Rejected: a scheduled backfill sweep.** A task scanning quotes in end states
and stamping matching records needs every part of the subscriber's
record-selection logic *plus* a task, a schedule and a repeated full scan of the
quote table, and is stale by up to the sweep interval. Strictly more code for
strictly worse data. Its only advantage — catching a state changed by direct SQL
— is not a failure mode anyone has.

**Chosen: a subscriber on `state_machine.quote.state_changed`,** the same event
`QuoteServicingTrigger` already uses, updating the newest record for the quote.

## Components

Two new classes and one new interface, all in `MerchantQuoteAgentPlugin\Audit`.

### `TerminalOutcomeSubscriber`

`final readonly`, implements `EventSubscriberInterface`. Holds all the filtering
and none of the persistence, so it is unit-testable against a fake.

```
state_machine.quote.state_changed → onQuoteStateChanged
  1. transition side is ENTER            the event fires twice per transition,
                                         leave then enter; only entering is news
  2. context version is LIVE             consistent with QuoteServicingTrigger
  3. state ∈ TERMINAL_STATES             the five above
  4. writer->recordTerminalOutcome($event->getTransition()->getEntityId(),
                                   $event->getStateName(),
                                   new \DateTimeImmutable())
```

`TERMINAL_STATES` is a private const list of the five technical names.

**No `AgentContext::STATE` guard, unlike `QuoteServicingTrigger`.** The agent
drives exactly two transitions — `process` in `OfferApplier` and `sent` in
`ReplyComposer` — and neither is terminal, so the subscriber cannot hear its own
writes. A comment says that; a guard that can never fire would not.

The LIVE-version guard is defensive rather than measured. `QuoteServicingTrigger`
needs its version filter because SwagCommercial's `QuoteHistoryWriter` mirrors
comments into the snapshot lane through `Context::createWithVersionId()`, which
drops context states; no equivalent mirroring of state transitions has been
observed. One line, consistent with the neighbouring subscriber, and correct on
its own merits: a snapshot-lane transition is not a merchant's decision.

No injected clock. `DecisionRecorder` uses `microtime(true)` directly and the
plugin has no PSR clock anywhere; the timestamp is constructed in the subscriber
and passed as an argument, which is what makes it assertable in the unit test.

### `TerminalOutcomeWriterInterface` / `TerminalOutcomeWriter`

One method: `recordTerminalOutcome(string $quoteId, string $state, \DateTimeImmutable $at): void`.

A separate interface rather than a second method on
`DecisionRecordWriterInterface`: `DecisionRecorder` needs only `write()`, and
widening the shared interface would force `FakeDecisionWriter` to implement a
method the recorder tests do not care about. A separate class rather than a
second method on `DecisionRecordWriter`, whose docblock describes an insert-only
payload mapper — this is a search-then-update against different rows.

```php
$criteria = new Criteria();
$criteria->addFilter(new EqualsFilter('quoteId', $quoteId));
$criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
$criteria->addSorting(new FieldSorting('id', FieldSorting::DESCENDING));
$criteria->setLimit(1);

$context = Context::createDefaultContext();
$id = $this->records->searchIds($criteria, $context)->firstId();

if ($id === null) {
    return;
}

$this->records->update([['id' => $id, 'terminalState' => $state, 'terminalAt' => $at]], $context);
```

`createdAt` is queryable even though `QuoteDecisionRecord` does not declare it:
`EntityDefinition::defaultFields()` adds `CreatedAtField` and `UpdatedAtField`
with `ApiAware()` to every definition (`EntityDefinition.php:466`), and field
compilation merges those with the attribute-derived ones. The `id` sorting is
a deterministic tiebreak; `created_at` is `DATETIME(3)` and a pass takes seconds,
so a tie is not expected, but an arbitrary-but-stable order beats an undefined
one.

`Context::createDefaultContext()` is system scope, which is what
`Protection(write: [Protection::SYSTEM_SCOPE])` on every field requires. A plain
`update()` gives decision 2 — last word wins — with no extra code.

A quote with no record at all is a silent no-op: the agent never serviced it,
and #33 is explicit that inventing a record would be wrong.

### Wiring

`src/Resources/config/services.php`, beside the existing audit services:

```php
$services->set(TerminalOutcomeWriter::class)->args([service('merchant_quote_agent_decision.repository')]);
$services->alias(TerminalOutcomeWriterInterface::class, TerminalOutcomeWriter::class);
$services->set(TerminalOutcomeSubscriber::class);
```

`autoconfigure()` is already on in this file, so implementing
`EventSubscriberInterface` is enough to get the `kernel.event_subscriber` tag.

## Error posture

The whole writer call sits in a try/catch that logs at error level and swallows.

A merchant clicking **accept** must never see a 500 because an audit write
failed, and the same is true of SwagCommercial's expiry task, which would
otherwise abort a batch part-way. This is the posture `NegotiationPipeline::record()`
already takes for the same reason. `LoggerInterface` is injected, as it is in
eight other classes in the plugin.

## The known gap

`admin_cancel` is reachable from `in_review`, which is the state the agent's own
pass sits in. Cancel a quote mid-pass and the subscriber stamps the record from
the *previous* pass, then `NegotiationPipeline`'s `finally` inserts a newer
record carrying no outcome. The quote's outcome is then attached to the
second-newest row.

Accepted, not fixed. It costs one unlabelled row — the new record, which never
gets an outcome — **and one mislabelled row**: the previous pass's record is
credited with an outcome that belongs to a different pass, with nothing marking
it suspect. For #22, which scores prompt variants against outcomes, that is
strictly worse than the unlabelled row sitting next to it: a dropped sample
versus a poisoned one. The window this needs is not bounded by how long a pass
takes to run. `QuoteServicingTrigger::queue()` dispatches onto a Messenger bus
rather than servicing inline, so the window runs from the trigger firing to
`DecisionRecorder::finish()` inserting the new row — trigger, queue latency,
and the pass itself. Under #21's 10,000-negotiation run it is queue depth, not
pass duration, that sets how wide this hole is. Closing it means coordinating
the subscriber with the pipeline's record lifecycle, which recreates the
two-owners problem #33 was split out of #19 specifically to avoid.

Documented in the subscriber's docblock so the next reader does not rediscover
it as a bug.

## The admin surface

One new card on #7's detail page, between **What the buyer was told** and **What
went wrong**, because that is the narrative order: asked → allowed → offered →
model → told → what happened → what failed.

- `merchant-quote-agent.detail.outcome` — the card title, "What happened to the quote"
- `terminalState` rendered through a label map, falling back to the raw technical
  name if Shopware ever adds a state the map does not know. A blank cell for an
  unrecognised value would read as "no outcome".
- `terminalAt` rendered through `Shopware.Filter.getByName('date')`, verified to
  exist in this Shopware version at
  `administration/src/app/filter/date.filter.ts`. Falls back to the raw value if
  the filter is unavailable.
- Both null — the ordinary case for a quote still in flight — renders one line,
  "Still in flight — no final outcome yet", not two em dashes. A missing outcome
  and an outcome of "nothing happened" are different facts.

Snippets in both `en.json` and `de.json`, matching the module's existing
convention.

**Risk acknowledged:** #39 records that nothing in this repository type-checks or
lints the administration TypeScript, and that a `repository.aggregate()` call
that does not exist reached review because of it. This change adds no new API
call — it reads two properties off a record the page already loaded and calls one
filter whose existence was checked against the shop's own source. The list page
is deliberately left alone: a sortable Outcome column would mean touching the
column config and the criteria, which is where that risk actually lives.

## Testing

**Unit — `tests/Unit/Audit/TerminalOutcomeSubscriberTest.php`,** against a fake
writer. `tests/Unit/Servicing/QuoteTriggerEventFixture.php` already builds a
`StateMachineStateChangeEvent` by hand; the same construction is reused rather
than reinvented.

- each of the five terminal states writes, with the state name and a timestamp
- `replied`, `reopen`, `in_review`, `open`, `change_requested`, `draft` do not
- the LEAVE side does not write, even for a terminal state
- a non-LIVE version does not write
- a writer that throws does not let the exception escape

**Integration — `tests/Integration/TerminalOutcomeTest.php`,** two levels.

*The writer, against the real DAL.* Two records for one `quoteId` with distinct
`createdAt`: assert the newer one carries both columns and the older is
untouched; assert a second call overwrites the first; assert an unknown `quoteId`
is a silent no-op. **This is the first SQL-level proof that `terminal_state` and
`terminal_at` exist and are spelled correctly** — #33 notes those two columns are
the only ones in the table nothing has ever written, checked by hand and never by
a test.

*The subscriber, end to end.* Insert a record for a fixture quote, drive a real
`accept` through `StateMachineRegistry` the way `ServicingTriggerTest` already
drives transitions, and assert the record came back stamped. This is the half
that proves the subscriber is actually **registered** — the class of defect #39
exists about, where every unit test passes and the feature does nothing.

## Corrections to existing code

Three docblocks currently assert the opposite of what will be true, and one test
constant is named for a state of affairs this work ends.

- `QuoteDecisionRecord` — "`terminalState` and `terminalAt` are reserved and
  never written here" becomes a pointer to the subscriber that writes them.
- `DecisionDraft` — same, in its mirror-list paragraph.
- `DecisionRecordWriter` — "The only class in Audit that touches Shopware" is no
  longer true. Restate the invariant as what it actually protects: the recorder
  and the draft stay plain PHP, and persistence lives behind an interface.
- `DraftMirrorsEntityTest::RESERVED_TERMINAL_FIELDS` — the exclusion stays,
  because the draft still does not carry these two columns and should not: a
  draft is one pass's insert, and the outcome is a later update by a different
  owner. Only the name and comment change to say that.

## Done when

- A quote reaching any of the five terminal states stamps `terminalState` and
  `terminalAt` on its newest decision record.
- A quote the agent never serviced stamps nothing and logs nothing.
- A later terminal state overwrites an earlier one.
- An audit write that fails does not fail the merchant's transition.
- The detail page shows the outcome, and shows a quote still in flight as such.
- An integration test proves both columns exist at the SQL level and that the
  subscriber is registered.

## Not here

The JSONL export (#34), which will carry these two columns once it exists.
Replay evaluation (#22). Any list-page column or filter over the outcome. Order
conversion detail beyond the `accepted` state — there is no column for an order
id and no consumer asking for one.
