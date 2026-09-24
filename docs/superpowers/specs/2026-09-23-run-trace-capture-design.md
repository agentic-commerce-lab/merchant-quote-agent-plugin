# Run trace capture

Date: 2026-09-23

## Status

Approved in chat on 2026-09-23. No issue yet. Delivered as three PRs from this
one spec (see **Delivery**).

Builds on
[`2026-08-29-quote-decision-audit-design.md`](2026-08-29-quote-decision-audit-design.md),
which owns the decision table, and
[`2026-09-16-anonymized-decision-export-design.md`](2026-09-16-anonymized-decision-export-design.md),
which owns the export this extends.

## Why

The internal test runs are the only source we have for improving the agent's
prompts, strategies and policy. Nearly everything a run did must therefore be
readable afterwards, through the export that already exists. Today most of it is
thrown away before the export ever sees it.

## What a run loses today

Measured on `main` at 8d395f1.

**Inside a pass** (a `merchant_quote_agent_decision` row exists):

- **Prompts.** `extract_prompt_hash`, `negotiate_prompt_hash` and
  `reply_prompt_hash` each hash only the system prompt (`ComposedPrompt`). The
  user message is stored nowhere: the quote total, the line table, the
  customer brief, the appended history results.
- **Answers.** Only the negotiate call leaves anything, and that is
  `raw_proposal`, which is `json_encode` of the *mapped* `NegotiateResponse`,
  not the provider's answer.
  - The extract answer survives only as the mapped `interpreted_asks`.
  - The intermediate "wants history" answers are dropped.
  - A reply rewrite the guard rejected exists only in a `warning` log
    (`ReplyComposer::reword`).
- **Per-call figures.** `recordModelCall` overwrites `model` and `model_host`
  with the last call's values and sums tokens and latency into one figure. The
  model that actually answered (the response's `model` field), the finish
  reason, and cached or reasoning tokens are never read.
- **Failures.** Only a successful call reaches `recordModelCall`. Retries and
  failed attempts leave nothing. A `ModelUnavailable` from extract or negotiate
  becomes an `escalation_reason`, and its message goes only to an `error` log
  (`NegotiationFailure`).
- **Policy.** `recordDecision` keeps `overall` only. `NegotiationDecision::$price`
  and `::$escalationReasons` are dropped.
- **Quote.** Only `total_net_before` and `total_net_after` are kept. The lines
  are not.

**Outside a pass** (no row at all):

- **Handler and preflight exits.** Every early exit in `ServiceQuoteHandler` and
  `ServicingPreflight` ends without a row:
  - no gateway;
  - lock busy;
  - quote not found;
  - stale trigger enum;
  - nothing new (fingerprint unchanged);
  - no pipeline;
  - crash budget reached;
  - attempt-counter write failed;
  - terminal state;
  - kill switch;
  - the `recordRefusal()` write itself failing.

  Several of these log at `debug`, which production suppresses.
- **A2CN inbound.** Refusals (400, 401, 404, 409, 503) exist only in the
  response. Accepted acts are mirrored to `merchant_quote_agent_a2cn_act`, but
  the export does not read that table.
- **A2CN outbound.** `ObserveQuoteHandler` has its own silent skip paths, and
  `SellerActEmitter` publishes acts the export never sees.
- **UCP and the assistant tools.** Requests leave no record beyond the quote
  data they change.
- **Correlation.** There is no pass id. The decision row's id is generated at
  write time (`DecisionRecordWriter`), so no log line and no other table can
  point at it.

## Decision

**A new trace table, one row per event, linked to the decision it belongs to.**
The export nests a pass's events inside that pass's decision line. Events
outside a pass become lines of their own.

Rejected alternatives:

- **A JSON column on the decision row.** The dashboard list loads up to 500
  decision rows with every column (`PASS_LIMIT` in
  `merchant-quote-agent-list/index.ts`). At roughly 100–150 KB of prompts per
  pass, that makes one list load 50 MB or more. Skipped runs and protocol
  traffic also have no decision row to live on.
- **A structured log file.** It needs no migration. But the dashboard export
  cannot reach it on a hosted shop, log rotation deletes it, and the eraser
  cannot clear it.

## 1. Storage

### Table `merchant_quote_agent_trace`

A DAL attribute entity, `Audit\TraceEvent`, next to `QuoteDecisionRecord`. It
follows the same rules as the decision record:

- every field is write-protected to system scope;
- it is `admin-api` only;
- string fields carry no `maxLength:` (see the floor note on
  `QuoteDecisionRecord`);
- the migration is hand-written.

| Column | Type | Meaning |
|---|---|---|
| `id` | BINARY(16) | |
| `decision_id` | BINARY(16) NULL, indexed | The pass this event belongs to. NULL outside a pass. |
| `quote_id` | BINARY(16) NULL, indexed | NULL only where the quote is not known (e.g. an A2CN 404 for an unknown session). |
| `customer_id` | BINARY(16) NULL, indexed | Where known. The eraser reads it. |
| `kind` | VARCHAR(32) | A closed vocabulary, `Audit\TraceKind` (see section 2). |
| `position` | INT NULL | The event's order within its pass, starting at 0. NULL outside a pass. |
| `occurred_at` | DATETIME(3) | When the event happened, not when the row was written. Pass events are buffered, so the two differ. |
| `meta` | JSON NULL | Structured data only: enums, numbers, hashes, ids, closed-vocabulary strings. Always exported. |
| `content` | JSON NULL | Anything that can carry the buyer's words, a model's words or account data. Exported only together with free text, and cleared by the eraser. |
| `created_at` | DATETIME(3) | DAL standard. |

**The split between `meta` and `content` is the privacy boundary.** It
replaces, for this table, the five-list classification `AnonymizedDecision`
does per column. Section 3 has the test that holds it.

The migration adds the table and nothing else. It is added to the uninstall
drop list in `MerchantQuoteAgentPlugin` (`UninstallDropsEveryTableTest` fails
otherwise).

### Linking a pass

`DecisionRecorder::begin()` generates the decision id (`Uuid::randomHex()`),
where `DecisionRecordWriter::write()` generates it today. It is stored on
`DecisionDraft::$id`, and the writer uses it instead of making a new one.

Everything that happens during the pass can then point at the row it will
belong to. The per-pass `info` log line in `NegotiationPipeline::record()` gains
`decisionId`. `recordRefusal()` does the same for its own row.

### Write timing

- **Pass events** are buffered on the draft (`DecisionDraft::$trace`, a list)
  and written by `DecisionRecordWriter` at `finish()`, after the decision row
  and in the same method. A failed trace write is logged at `error` and
  swallowed. The decision row stands, for the same reason
  `NegotiationPipeline::record()` gives for the decision write: an audit write
  must never fail a pass.
- **Events outside a pass** go through `Audit\TraceWriter::write()`
  immediately. It uses a DAL create in system scope and never throws; a failure
  is logged at `error`. This is the only class besides `DecisionRecordWriter`
  that inserts trace rows. `RecorderOwnershipTest` is extended to hold that.

### Size and retention

There is no cleanup job, the same as for decision rows: rows live until the
plugin is uninstalled with "remove all data". A pass with five model calls
stores roughly 100–150 KB.

This is a deliberate ceiling. Add a scheduled cleanup once a shop's table size
matters. It is not built now because the runs this serves are the ones we want
to keep.

Every stored body (request, response, error body, tool input) is capped at
64 KB. A capped body is cut, and its event's `meta` carries
`truncated: ["<field>", ...]`. The cap is a trust-boundary control, not a
convenience. The HTTP routes in section 2 are reachable by any storefront
caller, and without a cap one request could write megabytes.

## 2. What gets recorded

`Audit\TraceKind` is the closed list. Each kind below says what goes into
`meta` and what goes into `content`.

### Inside a pass (PR 1)

**`model_call`** is one event per logical model call, successful or not,
recorded in `ModelPlatform::send()`.

- `meta`:
  - `purpose`: `extract`, `negotiate` or `reply`. History rounds are `negotiate`
    calls, and their order is carried by `position`;
  - `requestedModel`;
  - `servedModel`, the response's `model` field;
  - `host`, via the existing `hostOnly()`;
  - `status`: `ok`, `failed` or `unusable_answer`;
  - `httpStatus`;
  - `latencyMs`;
  - `promptTokens`, `completionTokens`, `cachedTokens`, `reasoningTokens`, each
    null when the provider does not send it;
  - `finishReason`;
  - `retries`, a list with one entry per failed attempt holding
    `{httpStatus, transportError: bool}`, collected by `ModelRetryStrategy`;
  - `errorClass`;
  - `truncated`.
- `content`:
  - `request`: the exact JSON body that was POSTed, meaning `model`, `messages`
    (system and user) and `response_format`. The bearer key never reaches the
    body; it travels as `auth_bearer`, and nothing records headers;
  - `response`: the decoded provider body;
  - `error`: `{message, body}` for a failed call, with the provider's error body
    when there was one.

`unusable_answer` is the case `ModelPlatform::object()` throws on: the call
succeeded, but the answer did not map onto the requested shape. Today that
answer is lost entirely, and it is exactly the answer worth reading.

The existing decision columns (`model`, `promptTokens`, `completionTokens`,
`modelLatencyMs`) keep their current meaning, because the dashboard and
`bench-score.mjs` read them.

**`reply_guard`**: a reply rewrite that `RewordingGuard` rejected, recorded in
`ReplyComposer::reword()`.

- `meta`: `{accepted: false}`.
- `content`: `{reason, reworded}`. The reason goes into `content`, not `meta`,
  because it quotes the model's text (`'it names a concession nobody
  authorised: ' . reset($found)`).

**`policy_verdict`**: the whole `NegotiationDecision`, recorded in
`recordDecision()` through `Audit\VerdictTrace`.

- `meta`: the decision's figures and enums, flattened one by one: `overall`,
  `priceKind`, `escalationReason`, `requestedDiscountPercent`,
  `discountPercent`, `perLineAsks` (a bool), `validityDays`,
  `counteredRequestPercent` and `escalationReasons`. Those are enums, money and
  flags. `escalationReasons` qualifies because `PriceEscalationReasons` builds
  each string from an enum value (`'price: ' . reason->value`).
- `content`: the whole encoded `NegotiationDecision`, encoded the way
  `InterpretationPayload` already encodes (`json_decode(json_encode())`). The
  tree cannot be `meta`: `QuoteEscalationDetails::$humanReviewRequests` are
  sentences the extract model wrote, and `lineUnitPricesNet` is keyed by
  line-item ids.

**`quote_before`** and **`quote_after`**: the full `QuoteSnapshot` at `begin()`,
and `AppliedOffer::$after` at `recordApplied()`.

- `meta`: `{lineCount}`.
- `content`: the snapshot, encoded the same way. It carries product labels and
  the quote's identity, so all of it goes into `content`, except
  `identity.companyName` and `identity.orderId`, which `Audit\QuoteTrace` drops
  at recording: neither is needed to measure a strategy, and the company name
  would sit next to the customer's pseudonym in every export with comments.
  Of `lifecycle.customFields`, only the plugin's own keys are kept (those
  starting `merchant_quote_agent_`, `merchantQuoteAgent` or `a2cn_`: the
  agent's state markers and the A2CN session and acts). Every other key is a
  field the merchant defined, which can hold a contact's name or e-mail.

### Outside a pass (PR 2)

**`skip`**: every exit named under "What a run loses today" that ends without
`service()` or `recordRefusal()`.

- `meta`: `{source, reason, trigger, attempt}`.
  - `source` is `handler`, `preflight` or `observer`.
  - `reason` comes from a closed enum, `Servicing\SkipReason`: `no_gateway`,
    `lock_busy`, `quote_not_found`, `stale_trigger`, `nothing_new`,
    `no_pipeline`, `crash_budget`, `attempt_write_failed`, `terminal_state`,
    `kill_switch`, `refusal_write_failed`, plus the observer's own reasons in
    PR 3.
- `content`: none.
- It is written through `TraceWriter` at the exit, next to the existing log
  line.

The existing log calls stay. The event is the durable twin of a log line that
production often drops, not a replacement for it.

Not recorded: `QuoteServicingTrigger`'s event filter (`isNotOurBusiness`,
leave-side transitions, non-insert comment writes, merchant comments). It
filters Shopware's own DAL events, most of which have nothing to do with the
agent. Recording it would add a row for every admin draft save.

### Protocol traffic (PR 3)

**`http`**: one event per request to the plugin's decision-bearing routes,
recorded by one kernel subscriber (`Audit\HttpTraceSubscriber`, on
`kernel.response`) and not by each controller.

- **Routes recorded:** those named `frontend.merchant_quote_agent.quote.*`
  (UCP, except `.schema` and `.spec`), `frontend.merchant_quote_agent.a2cn.messages.*`,
  `.a2cn.acts` and `.a2cn.record*`.
- **Routes not recorded:**
  - the static documents: `.a2cn.did`, `.a2cn.mandate`, `.a2cn.discovery`,
    `.quote.schema`, `.quote.spec`. They are identical for every caller and
    decide nothing;
  - the `.a2cn.not_found` catch-all. Any caller can reach it with any path,
    so recording it is a free table-growth primitive.
- **Identity routes** (`.authorize*`, `.authorization_request`): `meta` only,
  never a body, because they carry OAuth codes and client secrets.
- `meta`: `{route, method, httpStatus, durationMs, sessionId, errorCode, truncated}`.
  - `sessionId` is the A2CN session from the route, when there is one.
  - `errorCode` is the refusal code from a JSON error response, e.g.
    `session_busy`.
  - `quote_id` is set whenever the route resolves to a quote: through the
    UCP route's `{id}`, or through the A2CN session. The plan confirms which
    identifier each route carries.
- `content`: `{requestBody, responseBody}`, for POST requests and for any
  response with status ≥ 400. GET success bodies are not stored, because
  polling would store the same acts again on every poll.
- Headers are never recorded, including `Authorization`, `DPoP` and signature
  headers.

**`assistant_tool`**: `RequestQuoteTool` and `QuoteStatusTool`, one event per
call.

- `meta`: `{tool, status}`.
- `content`: `{input, output}`.

**`seller_act`**: one event per outcome of `SellerActEmitter` and
`SellerActPublisher`, whether published or skipped (inert, unchanged,
violation).

- `meta`: `{sessionId, seq, actType, offerHash, result}`.
- `content`: the signed act as mirrored.

`ObserveQuoteHandler`'s exits (no gateway, quote gone, failure) are `skip`
events with `source: observer`.

Inbound buyer acts need no event of their own: the `http` event's request body
is the act.

## 3. Export and erasure

### Line shape

Every exported line gains a `record` field.

- **`record: "decision"`** is today's line, unchanged, plus
  `trace: [ ...events ]`. The events are that pass's rows ordered by
  `position`, each exported as
  `{kind, occurredAt, meta, content?}`. `content` is present only with free
  text.
- **`record: "event"`** is an event outside a pass, in the same range:
  `{record, id, quote, customer, kind, occurredAt, meta, content?}`.

The range filter applies to `created_at` for decisions and `occurred_at` for
events. Both are half-open, as today. Decision lines and event lines are two
passes over the range, not interleaved: first all decisions, then all events.
Interleaving by time would mean merging two cursors, and consumers group by
`record` anyway.

`--outcome` filters decisions. When it is set, no event lines are written,
because an event has no outcome. The notice that a filter applied says so.

`DecisionExportStream` loads each decision's trace rows in one query per
decision, not one per page: memory then holds one pass's prompts (~150 KB)
rather than a whole page's (500 passes, ~75 MB). The export stays streamed, at
the cost of one query per decision.

### Pseudonymization

Every id passes through `ExportPseudonym`: event `id`, `decision_id` (as the
decision line's `id`, which it already is), `quote_id`, `customer_id`, and
`meta.sessionId` (it is derived from the quote id, so it identifies the quote).

`occurred_at` and every other `meta` field are exported verbatim. That is what
the `meta` classification means.

### The privacy gate holds by test

`TraceMetaCoverageTest` builds one representative event per `TraceKind`
through its real recording path. It asserts that each kind's `meta` keys equal a
list declared next to `TraceKind`, and that every leaf of `meta` is an int, a
float, a bool, null, or a string drawn from an enum, a hash, an ISO timestamp
or a route name.

A new kind, or a new `meta` key, fails the test until someone classifies it. It
is the same rule `ExportFieldCoverageTest` applies to decision columns: nothing
is decided by omission.

A fixture test like the one `AnonymizedDecisionTest` already has for the email
and customer number greps a no-free-text export for the fixture's buyer
comment, product label and email, and finds none of them.

### Free text

`content` follows the existing comments rule exactly:

- the dashboard **Export** includes it;
- **Export without comments or prompts** leaves it out;
- the CLI leaves it out unless `--include-comments` is given.

The notices in `DecisionExportCommand::report()` and the menu labels name what
now rides along: the model's full prompts and answers, request bodies and quote
snapshots.

`docs/for-merchants.md` gets a section on traces. It covers what is stored, that
it stays in the shop unless exported, how big it gets, and that nothing cleans
it up.

**This moves two things out of the "never leaves" list** in
`docs/for-merchants.md`: the quote number and the details behind a history
lookup. Neither leaves in its own field, but both are in the prompts the model
was shown, and the prompts are free text. The chat choice was "same as
comments", made knowing that traces carry customer history.

The pseudonym promise does hold inside `content`: the decision's own ids
(record, quote, customer, channel, revision, strategy version) are swapped
for their pseudonyms in the encoded content at export time
(`AnonymizedTrace`), because a quote snapshot carries them raw.

### Erasure

`DecisionEraser::forget($customerId)` also sets `content` to NULL on every trace
row where `customer_id` matches, or where `quote_id` is one of that customer's
quotes as the decision rows name them. `meta` stays, which is the same
reasoning that keeps the decision rows themselves. The command's output counts
the trace rows it cleared.

### Permissions

`merchant_quote_agent_trace:read` is added to the `merchant_quote_agent.viewer`
role, so the role covers the new entity the way it covers decisions. The export
route stays gated on `merchant_quote_agent_decision:read` alone: the trace is
part of the decision record, not a second thing to grant. The export reads
traces under the caller's context, like decisions. The plan confirms with a
viewer-role integration test that this read succeeds.

No privilege grants writing or deleting traces. The `deleter` role's
`merchant_quote_agent_decision:delete` does not cascade (there is no FK). A
deleted decision's trace rows therefore stay, with a dangling `decision_id`, and
the export skips them: they are exported neither as `trace` nor as events.

## Not in scope

- **Admin UI for traces.** The decision detail page could render the
  `trace` list later. The export is what was asked for.
- **Retention and cleanup.** See section 1.
- **Recording the trigger's event filter.** See section 2.
- **Post-pass writes in `ServiceQuoteHandler`** (fingerprint stamp, marker
  release) that throw after the row is written. They surface through Messenger's
  retry, and a trace there would need the decision id to travel back out of the
  pipeline. Revisit if one is ever seen failing.
- **Backfilling.** Traces start with the release that ships them.

## Delivery

One spec, three PRs, each one green and useful on its own:

1. **Pass traces.**
   - The table, entity, migration and uninstall entry, plus `TraceKind`.
   - The decision id at `begin()`.
   - `model_call`, `reply_guard`, `policy_verdict`, `quote_before` and
     `quote_after`.
   - Export nesting, the pseudonyms, the coverage test, the eraser, the ACL and
     the docs.
2. **Skipped runs.** `TraceWriter`, `SkipReason`, the handler and preflight
   exits, event lines in the export.
3. **Protocol traffic.** `HttpTraceSubscriber`, `assistant_tool`, `seller_act`,
   and the observer's skips.

## Testing

- **Unit (no kernel), for each recording site:**
  - the event's kind, `meta` and `content`, for a success and a failure where
    the site has one;
  - `ModelPlatform` with a `MockHttpClient`: a 200, a 500 then a 200 (one
    retry), a 500 then a 500, and a 200 whose body does not map;
  - the 64 KB cap and its `truncated` marker.
- **Coverage:** `TraceMetaCoverageTest`, and `RecorderOwnershipTest` extended
  to trace inserts.
- **Export:**
  - line shape with and without free text;
  - pseudonyms;
  - `--outcome` suppressing event lines;
  - a dangling `decision_id` being skipped.
- **Eraser:** `content` cleared by customer and by quote, `meta` kept.
- **Integration (test shop):**
  - the migration on MySQL 8. CI never executes migration SQL, and a
    constraint MySQL rejects has already shipped once (the strategy assignment
    table, fixed by #185);
  - one real pass, whose export line carries a `trace` with one `model_call`
    per call made;
  - the ACL: a viewer-role export succeeds.
