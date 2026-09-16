# Recording the escalation that never reaches the pipeline

Date: 2026-09-16

## Status

Approved. Written for issue [#35](https://github.com/agentic-commerce-lab/merchant-quote-agent-plugin/issues/35)
(2.4c). Trades directly against
[`2026-08-29-quote-decision-audit-design.md`](2026-08-29-quote-decision-audit-design.md),
which is where the invariant this change touches is stated.

## Context

`NegotiationPipeline::service()` opens a `merchant_quote_agent_decision` record
with `recorder->begin()` and closes it with `recorder->finish()` in a `finally`.
Those are the only two call sites in the whole plugin:

```
$ grep -rn '\->begin(\|->finish(' src/
src/Negotiation/NegotiationPipeline.php:53:        $this->recorder->begin($snapshot, $context);
src/Negotiation/NegotiationPipeline.php:106:                $this->recorder->finish($pass, $error);
```

`ServicingPreflight::check()` runs *before* that, from
`ServiceQuoteHandler::servicePass()`, and on one of its three refusal paths it
takes a visible action: an `InvalidQuoteAgentConfiguration` makes it call
`QuoteEscalator::escalate(..., NotConfigured)`, which writes the
`merchant_quote_agent_escalated` marker, optionally posts a comment into the
buyer-facing conversation, and fires the admin notification. Then `check()`
returns `null` and `servicePass()` returns without ever calling the pipeline.
No record is written.

### What that costs, measured rather than asserted

**#7's page cannot see the quote at all.** Both admin pages are driven solely by
rows from `merchant_quote_agent_decision`:
`merchant-quote-agent-list/index.ts` searches the repository over a `createdAt`
range and `merchant-quote-agent-detail/index.ts` does `get(id)` plus a
`quoteId`-filtered search. Nothing anywhere reads the `merchant_quote_agent_escalated`
custom field, the notification, or the Flow event. A quote escalated as
misconfigured is not a row with gaps — it is absent.

**The page already has the copy for the row that does not exist.** `snippet/en.json`
carries, under `escalationWhy`:

> "The agent is switched on but its configuration is incomplete or invalid, so
> it answered nothing rather than guess. The technical details below name the
> fields."

"The technical details below" is the `violations` aside that
`merchant-quote-agent-detail` renders under the escalation sentence. The
vocabulary (`escalation.not_configured`, "Agent not configured") exists in both
`en.json` and `de.json`. #7 was built expecting these rows; the write side never
arrived. No admin change is needed by this work, which is itself evidence that
the record — not a join — is the shape #7 expected.

**#21 undercounts the bucket it most needs.** The 10,000-negotiation run's
headline readouts are counts and shares grouped by `outcome`, and
`DecisionRecordGuardsTest::testTheValueHandledAggregationCountsEachQuoteOnce`
and `testTheDiscountAverageExcludesEscalatedPasses` pin that those aggregations
run over this table alone. A misconfigured sales channel produces zero rows, so
the escalated share is wrong by an amount nothing in the run can report. That is
what moved this from a #7 follow-up to a V1 prerequisite: records cannot be
retrofitted onto a run that already happened, which is the same argument that
put #19 ahead of its siblings in the first place.

### The invariant being traded against

From the audit design:

> Keeping the pipeline the sole owner of the record's lifecycle is what makes
> `begin`/`finish` a single-owner invariant instead of something three classes
> can each half-start.

That invariant is evidenced, not declared: `RecordedPassTest` (ten methods) and
`RecordedOutcomePathsTest` (five) each drive `pipeline->service()` through
`PipelineHarness` and assert `assertCount(1, $harness->writer->drafts)`, and
`DecisionRecordTest::testARealPassWritesARealRow` does the same against the real
DAL. A second class calling `begin()`/`finish()` would make "exactly one record
per pass" a property of two lifecycles instead of one, and the fifteen unit
tests would no longer be able to prove it between them.

## Decisions

### 1. Shape: a narrow write that is not part of the `begin`/`finish` lifecycle

`DecisionRecorder` gains one method:

```php
public function recordRefusal(
    QuoteSnapshot $snapshot,
    PassContext $context,
    QuoteEscalationReason $reason,
    array $problems,
): void
```

It builds a `DecisionDraft`, fills it, and hands it to the writer in one call.
It never reads and never assigns `$this->draft`.

The four shapes the issue asked to be considered, and why this one:

**A second owner (preflight calls `begin`/`finish`).** Rejected. It buys
nothing this does not: a preflight refusal has no stages to accumulate from and
no window during which anything else can record, so the whole begin-accumulate-finish
lifecycle would collapse into two adjacent calls whose only effect is to make
the invariant a two-place property. `ServicingPreflight` is at three constructor
parameters and would fit — the issue is right that the cost is architectural
rather than mechanical, and there is no mechanical benefit to pay for it.

**The pipeline opening the record earlier, so preflight runs inside its
lifetime.** Rejected, and the strongest of the rejected options. It would need
either preflight moved into `NegotiationPipeline` — where it does not belong,
since its job is deciding whether there is a pipeline pass at all, and it
answers `null` to the *handler*, not to the pipeline — or `begin`/`finish`
hoisted into `ServiceQuoteHandler`. The handler is at five constructor
parameters, at the cap, with no room for a recorder; and hoisting would newly
put every currently-silent refusal (unchanged fingerprint, absent gateway,
unacquired lock, kill switch, terminal state) inside a record's lifetime, so
each would need an explicit suppression to stay silent. That trades one visible
gap for five silent ones, each of which can only be got wrong in the direction
of a false row.

**#7 joining escalations off the quote itself.** Rejected, on #21. The marker
`merchant_quote_agent_escalated` carries a reason value and nothing else — no
timestamp, no trigger, no attempt, no sales channel, no problems list, and only
the most recent reason per quote, since `QuoteEscalator` overwrites it. A page
could render "escalated, not configured" from it. A `TermsAggregation` over
`outcome` cannot, and that aggregation is #21's headline readout. The issue left
this to whoever built the page; the page now exists, and it is the run, not the
page, that decides.

**A narrow refusal entry point.** Chosen. `begin()` and `finish()` keep exactly
one caller, so the single-owner invariant is unchanged and the fifteen existing
tests still prove what they proved. `recordRefusal()` is a different kind of
thing — a complete row written in one call, with no window during which a draft
is open — and the property that makes it safe is stated in code rather than
prose: it touches no shared state, so there is no interleaving to reason about.

The honest cost: `DecisionRecordWriterInterface::write()` now has two callers
instead of one, and "exactly one row per pass" becomes "exactly one row per pass,
plus one per preflight escalation". §5 is what keeps that checkable.

### 2. Only the escalating refusal gets a row

`ServicingPreflight` refuses on three paths. Only one writes a row.

| Refusal | Visible to the buyer or merchant | Row |
|---|---|---|
| `InvalidQuoteAgentConfiguration` | escalation marker, buyer comment (when enabled), admin notification | **yes** |
| Settings `null` — the kill switch | nothing; a `debug` log line | no |
| A state SwagCommercial will not edit | nothing; an `info` log line | no |

This is the issue's own line, kept: a record exists to explain a visible agent
action. A merchant who paused the agent does not want a row per buyer comment
telling them the agent is paused, and an accepted quote that a trigger touched
is not an event. Both remain correctly silent, alongside the unchanged
fingerprint, the absent gateway and the unacquired lock, which this change does
not touch at all.

### 3. One row per refused pass, not one per buyer comment

`QuoteEscalator::escalate()` returns early when the marker already holds this
reason, so a misconfigured shop with a talkative buyer gets one comment and one
notification however many times it is triggered. The record does **not** follow
that rule: every refused pass writes a row, including the ones whose comment the
marker suppressed.

Two reasons, and the second is the load-bearing one.

The table is a **pass** log, not an event log. Every column on it is pass-scoped
— `triggerReason`, `attempt`, `durationMs`, `revisionVersionId` — and the
pipeline already writes one row per pass on paths where `OfferRound::escalated()`
calls the same suppressed `escalate()` and the buyer hears nothing. Recording a
preflight refusal per comment rather than per pass would make this the one place
in the table where a row means something else.

And it is the number the merchant needs. "My configuration was broken and one
buyer was told" is the marker's business. "My configuration was broken and
fourteen asks went unanswered over two days" is the audit trail's, and it is the
undercount #35 is about — fixing it to one row per quote would replace a zero
with a one and still hide thirteen.

`DecisionRecordGuardsTest::testTheValueHandledAggregationCountsEachQuoteOnce`
already establishes that #21 folds rows to quotes where it needs a per-quote
number, so the extra rows cost that run nothing.

### 4. What the row says, and what it deliberately leaves null

Written: `quoteId`, `quoteNumber`, `salesChannelId`, `customerId`,
`currencyIso`, `triggerReason`, `attempt`, `revisionVersionId`,
`revisionUpdatedAt`, `totalNetBefore` — the identity block, exactly as
`begin()` writes it, because `recordRefusal()` uses the same private factory —
plus `outcome = 'escalated'`, `escalationReason = 'not_configured'`, and
`violations = $e->problems`.

`violations` is reuse, not a new meaning. `DecisionRecorder::recordProposal()`
already writes the escalation detail there ("WHY it was refused, not just that
it was"), `merchant-quote-agent-detail` already renders it as the aside under
the escalation sentence, and the `not_configured` snippet already promises it:
"The technical details below name the fields." The problems list is precisely
the list of fields. It is admin-API-only, like the rest of the row — the rule it
must not break is `QuoteEscalator`'s, that nothing internal reaches the
*buyer-facing comment*, and that rule is untouched here: `escalate()` still
takes no text.

Left null, each for a reason:

- **`durationMs`.** #21 reads this as servicing latency and computes p50/p95
  over it. A refusal is a settings read and two writes; folding sub-millisecond
  rows into that distribution would deflate both percentiles with something that
  is not a servicing pass. A pass that did not run has no duration, and null is
  how this table says so.
- **`band`, `maxDiscountPercent`, `interpretedAsks`, `model`, `modelHost`, the
  three prompt hashes, tokens, `modelLatencyMs`, `authorized`, `verified`.** No
  classification ran, no model was called, nothing was authorised. Writing a
  zero or an empty object would be a claim.
- **`errorClass` / `errorChain`.** `InvalidQuoteAgentConfiguration` was caught
  and handled; it is the reason, not a failure. #21 reads `errorClass` as
  "failure count by cause", meaning passes that threw, and
  `RecordedPassTest::testAThrownGatewayFailureStillWritesARecordAndRethrows`
  is what pins that meaning. The reason lives in `escalationReason`, where the
  page looks for it.

`attempt` is the crash-budget counter as it stands on the quote at refusal time
— the same pre-increment value `ServiceQuoteHandler::claimAttempt()` returns,
read the same way. §6.

### 5. The single-owner invariant becomes a test instead of a docblock

New: `tests/Unit/Audit/RecorderOwnershipTest.php`, which scans `src/**/*.php`
for `->begin(` and `->finish(` and asserts the only file containing either is
`src/Negotiation/NegotiationPipeline.php`.

This is the price of §1 and the reason §1 is affordable. Until now the invariant
was carried by a spec sentence plus fifteen tests that each happen to see one
draft; the argument that a second write path does not break it is only as good
as the next person's memory of why. A source scan states it in one place, fails
the moment a second class opens a record, and costs twenty lines. The codebase
already pins structural facts this way — `DraftMirrorsEntityTest` reflects draft
properties against entity fields for exactly the reason that the framework will
not catch the drift.

A regex over source is a blunt instrument, and it is kept blunt on purpose: it
matches the two arrow-call spellings and nothing else, over a directory where
those two method names have exactly two call sites today. A false positive is a
new `->finish(` on some unrelated class, which is a thirty-second edit to the
exclusion list with a reviewer's eye on it — which is the point.

### 6. Plumbing: `PassContext` moves ahead of the preflight

`ServicingPreflight::check()` takes a third parameter, `PassContext $context`
(two → three arguments; the constructor goes three → four with the recorder,
both inside the five cap). `ServiceQuoteHandler::servicePass()` builds the
context before calling `check()` rather than after:

```php
$context = new PassContext(ServicingTriggerReason::from($message->reason), self::attemptsOn($snapshot));
$settings = $this->preflight->check($gateway, $snapshot, $context);
```

`claimAttempt()` reads the counter through the same `attemptsOn()` helper, so
there is one definition of "the counter at pass start" and the refusal row and a
serviced pass's row cannot disagree about it.

One behaviour change falls out, and it is an improvement rather than a cost.
`ServicingTriggerReason::from()` throws on a value the enum no longer knows,
and today that happens after the preflight; from here it happens before. The
existing comment explains why it sits ahead of `claimAttempt()` — "a stale enum
value must throw here, before anything is mutated" — and moving it one step
earlier serves that same rule better: a message queued by a previous deploy now
fails before the escalator can write a comment and a marker on the strength of a
trigger nothing can name.

### 7. The audit write can never fail servicing

`ServicingPreflight` wraps the `recordRefusal()` call in a `try`/`catch
(\Throwable)` and logs at error level, mirroring `NegotiationPipeline::record()`.

The audit design states the trade and it applies unchanged here: a throw would
roll the message back into Messenger's retry, and the retry would re-run the
preflight against a quote whose marker is now set — so the buyer would not be
comment-spammed, but three redeliveries would be burnt and the notifier would be
the thing deciding what the merchant sees. **A missing record beats a message
Messenger has to fight.** The `error` log line the preflight already writes for
the misconfiguration stays as it is and remains the backstop.

The call goes **after** `escalate()`, never before: the escalation is the thing
that must happen, and the record describes it.

## Questions I could not ask

Recorded as required — this work ran without a channel to the requester, so each
is an assumption, not an agreement.

1. **Should the kill switch and the terminal-state refusal also get rows?**
   Assumed no (§2). Both are silent by design and neither is an agent action a
   merchant would look for in an audit trail. Adding them later is additive; a
   merchant who has learnt to ignore a noisy trail is not recoverable.
2. **Should a refusal whose comment the marker suppressed still write a row?**
   Assumed yes (§3). This is the decision most likely to be argued, because it
   is the only one where "the audit trail" and "what the buyer saw" genuinely
   come apart. If the answer is per-comment instead, `QuoteEscalator::escalate()`
   returns a `bool` and the preflight records on `true` — a small change,
   deliberately left unmade.
3. **Should `durationMs` be measured for a refusal?** Assumed no (§4). A
   stopwatch around the settings read would be honest about itself and
   dishonest inside #21's latency distribution.
4. **Should the configuration problems go into the row at all?** Assumed yes
   (§4). The constraint messages can echo the offending value, which is why
   `QuoteEscalator` refuses to carry them to the buyer. The audit row is
   admin-scope-only and write-protected against `USER_SCOPE`
   (`DecisionRecordGuardsTest::testAnAdminScopedWriteIsRejected`), and the
   admin copy already promises them.
5. **Should #7's page change?** Assumed no. The list page's `disposition()`
   will fold these rows into `needsReview` until
   `EscalationResolutionSubscriber` stamps `resolvedAt`, which is the correct
   reading of a quote waiting on a human, and the detail page renders the
   reason, the sentence and the aside with no new snippet.

## Risks

- **A misconfigured shop with heavy traffic writes a row per trigger.** That is
  §3's deliberate choice, and it is the one that could look like noise on #7's
  list page before it looks like signal. Bounded in practice by the same thing
  that bounds servicing: one row per trigger, not per retry, since the refusal
  neither throws nor stamps.
- **The ownership test is a regex.** §5. It cannot see a call through a variable
  or a dynamic method name; it catches the mistake a person actually makes,
  which is writing `$this->recorder->begin(` in a second class.
- **Two writer call sites is a real widening.** Nothing except §5 stops a third.
  The mitigation is that §5 fails loudly and cheaply, not that the widening is
  free.
- **A row with a null `durationMs` is new to this table.** Every existing row
  has one, including thrown passes. Nothing aggregates it in a way that breaks —
  `AvgAggregation` skips nulls — but a future percentile implementation that
  coalesces null to zero would be wrong, and would be wrong quietly.

## Testing

**Unit.**

1. `DecisionRecorderTest` — `recordRefusal()` writes exactly one draft carrying
   the quote identity, `outcome = 'escalated'`, the reason, and the problems in
   `violations`; and `durationMs` is null.
2. `DecisionRecorderTest` — a `recordRefusal()` between a `begin()` and a
   `finish()` writes its own row and leaves the in-flight draft untouched: two
   drafts, the second still carrying what the pass recorded. The failure mode
   §1 claims is impossible, asserted rather than argued.
3. `ServicingPreflightTest` — the misconfigured channel records one refusal; the
   disabled channel and each terminal state record none. The existing four tests
   stay, with the fixture supplying a recorder.
4. `ServicingPreflightTest` — a writer that throws does not stop `check()`
   returning `null`, and the escalation still happened.
5. `RecorderOwnershipTest` — §5.
6. `ServiceQuoteHandlerTest` — the trigger reason and attempt that reach the
   preflight are the same ones that reach the pipeline.

**Integration, on the shop.** `ServicingConfigGateTest` already drives the real
container's preflight through the real handler twice against a misconfigured
channel and asserts exactly one comment. Extended in place:

7. Two triggers against a missing API key produce **two** decision rows and
   **one** comment — §3's asymmetry, pinned where it is visible, read back
   through `merchant_quote_agent_decision.repository`.
8. That row's `outcome` is `escalated`, its `escalationReason` is
   `not_configured`, and its `violations` names the API key — the row #7's
   `not_configured` copy has been describing to nobody.
9. A disabled sales channel writes no row.

## Non-goals

- Records for the unchanged fingerprint, the absent gateway, the unacquired
  lock, or the missing pipeline. Unchanged and still correctly silent.
- Any change to `QuoteEscalator`'s copy, its marker rule, or its return type.
- Any change to #7's pages or snippets.
- Retention or pruning of the extra rows (the audit design's position stands: a
  prune command if a pilot asks).
