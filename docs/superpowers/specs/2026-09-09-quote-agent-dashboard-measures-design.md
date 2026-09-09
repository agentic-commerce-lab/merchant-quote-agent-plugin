# Quote agent dashboard: success measures

Date: 2026-09-09

## Status

Approved, not yet implemented.

## Context

The dashboard (`merchant-quote-agent-list`) currently reports what the agent
*did*: order placed, needs review, quotes received, expired unanswered, value
handled, and average discount against the cap. Those are activity figures. None
of them answers whether the agent is worth running — whether its guardrails are
calibrated, whether the deal desk keeps up, whether autonomy costs margin, or
whether any of it is faster than a human.

Four success measures replace them, in full:

1. **Auto-execution rate** — share of negotiations the agent closes within
   guardrails without human escalation. Rising over time signals
   well-calibrated guardrails, not just more autonomy.
2. **Escalation resolution time** — average time for the deal desk to resolve
   an escalated negotiation, benchmarked against an SLA set in the guardrail
   config.
3. **Price retention** — average realized discount on agent-negotiated deals
   versus the same figure on deals the agent never touched, to confirm the
   agent is not trading margin for speed.
4. **Deal cycle time** — time from RFQ submission to confirmed deal, against
   the same figure for equivalent deal sizes the agent never touched.

Measure 3 is deliberately **not** gross margin. Margin needs COGS, and neither
this plugin nor a typical B2B catalog carries purchase prices. What the shop
does have is the original price and the price it sold at, so the measure is
price retention and is labelled as such.

### The queue never drains

Separately, a defect surfaced while specifying the default listing filter.
`disposition()` lets only `accepted` outrank the last pass's outcome:

```js
if (terminalState === ORDER_PLACED_TERMINAL_STATE) {
    return 'orderPlaced';
}

return (outcome && DISPOSITIONS[outcome]) || 'other';
```

So an escalated quote that ends **declined, expired, cancelled or withdrawn
reads "Needs review" forever**, and so does one a human has already answered
while the buyer thinks it over. This is currently pinned as intended behaviour
by `decision.check.mjs:117`:

```js
assert.equal(disposition('escalated', 'declined'), 'needsReview');
```

That assertion is wrong and this spec changes it. It matters more than it did
yesterday, because the grid's default filter becomes `needsReview` — a queue
that never drains would be the first thing a merchant sees.

The accept path itself is sound and needs no change.
`TerminalOutcomeSubscriber` listens to the core `state_machine.quote.state_changed`
event, so it fires regardless of who drove the transition; `TerminalOutcomeWriter`
stamps the newest pass; and `foldToQuotes` reads `terminalState` from *any*
pass, not just the latest. A human answering an escalation followed by a
customer accepting therefore already flips the row to *Order placed*. Only the
non-accepting terminal states and the answered-but-undecided case are broken.

### What the data supports

Measured 2026-09-09 against both lanes, because the two shops sit on opposite
sides of the released/trunk split (see `swagcommercial-version-map`):

| field / table | 7.13.1 (agenticquote, hoelshare) | 7.12.0 (b2bseller) |
|---|---|---|
| `quote_history` table + entity | present | **absent** |
| `quote.requestedAt` | present | **absent** |
| `quote.quoteCreatedAt` | present | **absent** |
| `quote.totalLineItemDiscount` | present | **absent** |
| `quote.sentAt` | present | present |
| `quote.subtotalNet`, `totalDiscount`, `amountNet` | present | present |
| `quote.orderId` (`ApiAware`) | present | present |
| `quote.order` association carries `ApiAware` | **no** | **no** |

Two consequences drove the design:

**`quote_history` is out.** It is a complete, authored audit trail of quote
actions with old→new state in a `changes` JSON, fully `ApiAware`, and it would
have made measures 2 and 4 retroactive over data already in the shop with no
new storage at all. It does not exist on SwagCommercial 7.12, so building on it
would blank two of four tiles on every released SwagCommercial. Rejected for
that reason alone.

**The `order` association is unreachable.** `quote.order` is declared without
`ApiAware` on both versions, so the admin API cannot traverse it. `quote.orderId`
*is* `ApiAware`, so the order date is fetched with a second read against the
core `order` repository by id.

## Decisions

1. Replace every existing figure on the dashboard with the four measures.
2. Record when an escalation was resolved, with a new column pair and a
   state-machine subscriber. Nothing else can supply it on 7.12.
3. Add an escalation SLA to the plugin config.
4. Fix `disposition()` so any terminal state outranks the pass outcome, and so
   a resolved escalation leaves the review queue.
5. Default the grid's filter to `needsReview`.
6. Keep the computation client-side, in the admin module.
7. Read version-dependent quote fields optionally, with `??` fallbacks, rather
   than branching on `CommercialCapabilities`.

## Design

### 1. Where the computation lives

Client-side, extending the existing single-read-and-fold in the list page. The
current ponytail comment states the invariant this preserves:

> the page reads every pass in the period in one request and folds it
> client-side, so the figures and the rows are one computation and cannot
> disagree

A server-side metrics endpoint would lift the `PASS_LIMIT` ceiling, but it
would also re-implement the decision vocabulary in PHP. This module has twice
shipped a vocabulary the backend had already moved on from
(`admin-vocabulary-drifts-from-backend-enums`); a second copy of the
disposition table is exactly that failure waiting to happen. The ceiling is
already surfaced honestly by the truncation banner, and this spec keeps that
mechanism.

### 2. Recording escalation resolution

Two new columns on `merchant_quote_agent_decision`, in a new migration, plus
matching fields on `QuoteDecisionRecord`:

| column | type | meaning |
|---|---|---|
| `resolved_at` | `DATETIME(3) NULL` | when a human first acted after this pass escalated |
| `resolved_state` | `VARCHAR(64) NULL` | the quote state they moved it to |

Both carry `Protection(write: [Protection::SYSTEM_SCOPE])` like every other
field on the entity, and neither is written by a servicing pass. They join
`terminalState` / `terminalAt` as the only stamped-later columns, which keeps a
record's *insert* under a single owner.

`EscalationResolutionSubscriber` is a sibling of `TerminalOutcomeSubscriber`,
on the same `state_machine.quote.state_changed` event, and copies its shape
exactly:

- enter side only, live version only
- find the newest pass for the quote; if its `outcome` is not `escalated`, or
  it already carries `resolvedAt`, do nothing
- otherwise stamp `resolvedAt` = now, `resolvedState` = the entered state
- every write wrapped in the same nested try/catch, for the same reason: a
  merchant clicking *send offer* must never see a 500 because an audit write
  failed, and a throwing logger must not fail the transition either

Writing through a `ResolutionWriterInterface` mirrors
`TerminalOutcomeWriterInterface` so the subscriber is unit-testable without a
DAL.

Reusing `TerminalOutcomeWriter`'s "newest pass" selection is deliberate: the
newest pass at the moment of a transition is, by construction, the pass that
concluded, because `QuoteServicingTrigger` only ever queues the next pass onto
Messenger rather than running it inline.

**Known limitation, accepted:** this measures escalations resolved from the
migration onward. Escalations already in the table have no `resolvedAt` and are
counted as open. The tile therefore reports "n open" alongside the average, so
a backlog of pre-existing escalations reads as a backlog rather than silently
dragging the average.

**Second known limitation:** any state transition by anyone closes the
escalation, including the buyer withdrawing the quote. The event carries no
author, so "the deal desk acted" cannot be distinguished from "something
happened" without `quote_history`, which 7.12 lacks. `resolvedState` is stored
precisely so this is inspectable after the fact.

### 3. The SLA config field

One field in the existing *Negotiation policies* card:

```xml
<input-field type="float">
    <name>escalationSlaHours</name>
    <label>Escalation SLA (hours)</label>
    <helpText>How long the deal desk has to answer an escalated quote. Used only
    to benchmark the dashboard's escalation resolution time — it does not
    change what the agent does. Blank means no benchmark.</helpText>
</input-field>
```

It steers nothing in the pipeline, so it does **not** join `QuoteAgentSettings`
or `NegotiationPolicyArray`. The dashboard reads it directly through
`systemConfigApiService` at global scope. Blank or absent means the tile prints
the measured time with no verdict.

### 4. The measures

All four are computed in `decision.ts` as pure functions over the folded quote
list plus the quote/order rows, so `decision.check.mjs` can assert them without
a Vue instance.

#### 4.1 Auto-execution rate

    autoExecuted = quotes with no escalated pass
    rate         = autoExecuted / all quotes serviced in the period

`foldToQuotes` gains an `escalated` flag set when **any** pass of the quote
escalated, not just the latest — a quote that escalated in round one and was
answered in round two did require a human.

The denominator is every quote serviced, not just concluded ones. Restricting
it to concluded negotiations would exclude unresolved escalations, so a shop
with ten quotes stuck in the review queue would report 100% auto-execution.
That failure mode is worse than counting a still-open, never-escalated quote as
auto-executed.

Subtext: "n escalated of m serviced" — which preserves the escalation queue
count that the retired *needs review* figure used to carry.

**Trend.** "Rising over time" needs a comparison, so `loadPasses()` widens its
range filter to `2 × rangeDays`. The grid rows and all current-period figures
filter to the recent half; the trend delta compares the two halves. The
effective truncation ceiling therefore halves, which the existing banner
already reports, and the ponytail comment on `PASS_LIMIT` is updated to say so.

#### 4.2 Escalation resolution time

    resolutionMs = mean(resolvedAt − escalated pass createdAt)

over escalated passes in the period that carry a `resolvedAt`. Reported with:

- "n of m within SLA" when `escalationSlaHours` is set
- "n open" for escalated passes with no `resolvedAt`

Mean rather than median: the merchant-facing wording is "average", and a median
over the handful of escalations a typical period produces is not more
informative.

#### 4.3 Price retention

Agent side, from our own records only, so it is version-proof:

    original = quote.netBefore        // max across passes, as foldToQuotes already computes
    realized = latest answered pass's totalNetAfter
    discount = (original − realized) / original × 100

Restricted to quotes whose disposition is `orderPlaced` — a discount on a deal
nobody bought is not realized.

A quote that escalated, was answered by a human, and was then accepted has no
answered pass at all, so `realized` is unknown and the quote is **excluded**
from this measure. That is the right exclusion, not a gap: the price the buyer
accepted was set by a human, and this measure is the discount on
*agent-negotiated* deals. Those quotes still count against the auto-execution
rate, and they still appear in deal cycle time, which does not care who set the
price.

Baseline, from quotes in the period with **no** decision rows and state
`accepted`:

    given    = totalDiscount + (totalLineItemDiscount ?? 0)
    discount = given / (amountNet + given) × 100

Both sets are restricted to the same net-value range (§4.5). The tile shows
both discounts and the gap.

**Known limitation on 7.12:** `totalLineItemDiscount` does not exist there, so
a merchant who negotiates by editing line prices rather than setting a quote
discount reads as 0% in the baseline. That understates the baseline and so
makes the agent look worse, which is the safe direction, but the tile carries a
footnote saying the baseline reads quote-level discounts only.

**Known limitation on both:** `netBefore` is the quote's stored total,
unadjusted for a quantity reduction or a line removal, so a buyer who halves a
quantity shrinks the total structurally and it reads as a concession. This is
the same limitation `QuoteBaselineLines` documents for
`DiscountTotalViolation`, inherited rather than introduced.

#### 4.4 Deal cycle time

One definition for both sets, so they are comparable:

    submitted = quote.requestedAt ?? quote.createdAt
    confirmed = order.orderDateTime, via quote.orderId
    cycleMs   = confirmed − submitted

`requestedAt` is the truer RFQ submission time and is populated on every 7.13
row sampled. It is absent on 7.12, where the `??` falls back to the quote's own
`createdAt`. Because the field is only ever *read* — never filtered or sorted
on — its absence yields `undefined` rather than a DAL error, so no capability
gating is required.

Agent set: accepted quotes with decision rows. Baseline: accepted quotes
without, inside the same value range.

#### 4.5 The equivalent-deal-size control

"Equivalent deal sizes" and "the same account segment" both need a segment this
shop does not model. Net value is the one comparable dimension available, so
one shared helper restricts the baseline to quotes whose `amountNet` falls
inside `[min, max]` of the agent-negotiated set. Used by both §4.3 and §4.4.

The range is computed from **`quote.amountNet`** on both sides, never from the
decision record's `netBefore`. `loadQuotes()` returns every accepted quote in
the period and the two sets are split by whether decision rows exist for it, so
the same field is available for both — and comparing a pre-negotiation
`netBefore` range against a post-negotiation `amountNet` would bias the
baseline upward by exactly the discount this dashboard is trying to measure.

When the agent set is empty, the range is undefined and both tiles report
unavailable rather than comparing against everything.

### 5. Loading

`load()` runs three reads, each degrading independently:

1. **`loadPasses()`** — existing, with the range widened to `2 × rangeDays`.
2. **`loadQuotes()`** — replaces `loadIntake()`. One search on `quote`,
   filtered to the period and `stateMachineState.technicalName = accepted`,
   reading `id`, `quoteNumber`, `createdAt`, `requestedAt`, `amountNet`,
   `totalDiscount`, `totalLineItemDiscount`, `orderId`. Only accepted quotes
   are needed, so this is a smaller read than the two aggregations it replaces.
3. **`loadOrderDates()`** — search the core `order` repository for the ids
   collected in step 2, reading `orderDateTime`, keyed by id.

Each result is **nulled, not zeroed**, on failure — the pattern `loadIntake`
established, and for the same reason: a viewer without `quote:read` or
`order:read` must see the figure absent, not see a confident zero. A tile whose
inputs are null renders as unavailable with the reason.

### 6. The disposition fix

```js
const CLOSED_NO_DEAL = ['declined', 'expired', 'cancelled', 'withdrawn'];

export function disposition(outcome, terminalState = null, resolvedAt = null) {
    if (terminalState === ORDER_PLACED_TERMINAL_STATE) {
        return 'orderPlaced';
    }

    if (terminalState && CLOSED_NO_DEAL.includes(terminalState)) {
        return 'closedNoDeal';
    }

    if (outcome === 'escalated') {
        return resolvedAt ? 'awaitingBuyer' : 'needsReview';
    }

    return (outcome && DISPOSITIONS[outcome]) || 'other';
}
```

- Any terminal state now outranks the pass outcome. `accepted` →
  `orderPlaced`; the other four → **`closedNoDeal`**, one new class.
- A resolved escalation becomes `awaitingBuyer`, which is literally true: the
  merchant answered and the ball is with the buyer. No new class needed, and
  the auto-execution rate still counts it against the agent because that reads
  the `escalated` flag, not the disposition.

`DISPOSITION_CLASSES`, `DISPOSITION_VARIANTS` (`closedNoDeal` → `neutral`),
`dispositionFilterOptions` and the snippet files gain the new class.
`foldToQuotes` carries `escalated`, `escalatedAt` and `resolvedAt` through.

The five terminal state names are duplicated between
`TerminalOutcomeSubscriber::TERMINAL_STATES` and this table. That duplication
already exists for `accepted` via `ORDER_PLACED_TERMINAL_STATE` and is
unavoidable across the PHP/JS boundary; both sites carry a comment naming the
other.

### 7. The grid

- `dispositionFilter` defaults to `'needsReview'`.
- The filter select still offers every class including `closedNoDeal`, so the
  default is a starting point rather than a hidden state.
- Columns, paging, the delete flow and the truncation banner are unchanged.

## Testing

**`decision.check.mjs`** — it already covers the disposition table, so the
additions go beside the existing assertions:

- `disposition('escalated', 'declined')` → `closedNoDeal` (**this replaces the
  assertion at line 117, which pinned the bug**)
- `disposition('escalated', 'expired' | 'cancelled' | 'withdrawn')` →
  `closedNoDeal`
- `disposition('escalated', null, null)` → `needsReview`
- `disposition('escalated', null, '2026-09-09T10:00:00Z')` → `awaitingBuyer`
- `disposition('offered', 'accepted')` → `orderPlaced` (unchanged)
- every class in `DISPOSITION_CLASSES` has a variant, and none is `success`
  (`shopware-mt-badge-variants`)
- `foldToQuotes` sets `escalated` from a non-latest pass
- each measure over: the empty set, one quote, an unresolved escalation, a
  quote with no order date, and an agent set whose value range excludes every
  baseline quote

**PHP** — `EscalationResolutionSubscriberTest`, modelled on the existing
`TerminalOutcomeSubscriber` test: leave side ignored, non-live version ignored,
non-escalated newest pass ignored, already-resolved pass ignored, a throwing
writer swallowed and logged, a throwing logger swallowed.

**Config** — the SLA field is read by the admin only, so the check is that
`QuoteAgentSettingsReader` does **not** grow a dependency on it.

**Live** — verify on both lanes, because a pass on one says nothing about the
other (`legacy-swagcommercial-branch-status`): agenticquote/hoelshare for the
7.13 fields, b2bseller for the `??` fallbacks. On b2bseller the price-retention
footnote and the `createdAt` fallback must both be visible.

## Consequences

- The dashboard no longer reports quotes received, expired unanswered, value
  handled, or discount against cap. The escalation count survives as the
  auto-execution tile's subtext; the rest is gone by decision.
- Escalation resolution time starts empty and fills over the weeks after
  release. Pre-existing escalations count as open.
- The truncation ceiling halves in effective range, because the trend needs two
  windows.
- Two more admin-API reads per dashboard load, both scoped to accepted quotes
  only.
- `order:read` becomes a soft requirement: without it, deal cycle time is
  unavailable and nothing else changes.
- `quote_history` remains unused. If the plugin ever drops 7.12 support, it
  makes measures 2 and 4 retroactive and precise, and removes the need for
  `resolved_at` entirely — worth revisiting then, not now.

## References

- `src/Resources/app/administration/src/module/merchant-quote-agent/decision.ts`
  — vocabulary, `foldToQuotes`, `disposition`
- `.../page/merchant-quote-agent-list/index.ts` — `PASS_LIMIT`, `loadIntake`
- `src/Audit/TerminalOutcomeSubscriber.php`, `TerminalOutcomeWriter.php` — the
  pattern §2 copies
- `src/Servicing/QuoteEscalator.php` — escalation writes a customField marker
  and a buyer comment; it does not transition the quote
- `docs/superpowers/specs/2026-09-08-legacy-swagcommercial-compatibility-design.md`
  — the 7.12 lane this spec must not break
- SwagCommercial `QuoteDefinition`, `QuoteHistoryDefinition` — read on both
  shops 2026-09-09; not vendored locally
