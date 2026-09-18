# Synthetic negotiation bench, and per-strategy KPIs in the admin

Date: 2026-09-17

## Status

Approved 2026-09-17. Implementation in progress.

## Context

Nothing in this repo answers the question a merchant asks first: *which
negotiation strategy should I run, and does a cheaper model negotiate worse?*
The three built-in strategies in `BuiltInStrategies` differ only in prompt text.
The plugin records enough per pass to tell them apart — `band`, `outcome`,
`discountPercentGranted`, `authorized`, `verified`, `terminalState`, token
counts, and `strategyVersionId` — but nothing groups those records by strategy,
and nothing generates enough of them to group.

This spec covers both halves, as two independently shippable tracks:

- **Track A — the bench.** Run `scenario × strategy × model` against a live
  shop and leave real decision records behind.
- **Track B — per-strategy KPIs.** Group the dashboard's existing measures by
  strategy so the comparison is visible in the admin.

They are separable on purpose. Track B is useful on real traffic with no bench
at all; Track A is useful with no admin change, because the rows it writes are
ordinary decision records. Together, Track B is the readout for Track A.

Related issues: #21 (the 10,000-negotiation stress run, which this is the
tooling for), #22 (the nightly replay loop, which requires the scenario format
defined here), #99 iteration 2 (A/B reporting between strategies, which Track B
is the reporting half of).

## Decisions taken

Recorded verbatim because each one closed a fork that would otherwise be
re-litigated during implementation.

**Metrics are the ones the system already has.** No new theoretical framework.
The four measures in `measures.ts` — auto-execution rate, escalation resolution
against SLA, price retention, deal cycle time — plus one addition below. Any
alignment with external bargaining theory happens later, against rows this
already produces.

**A fifth measure: tokens per negotiation.** Cost is a first-class comparison
axis between models, and `promptTokens`/`completionTokens` are already columns.
Money is deliberately *not* reported: the plugin records `model` and
`modelHost` but carries no price table, and a currency figure derived from rates
we do not have would be a confident fabrication. Tokens are what we can measure,
so tokens are what we show. A per-model rate table is a follow-up, not this.

**The swapped model is the negotiating one.** All three calls a pass makes —
extract, negotiate, reply — run on the model under test, driven by one
`ModelAccess`. Not a separate scoring judge over transcripts, and not a swapped
buyer model; both were considered and cut. The question being answered is
whether a different model negotiates as well inside the same bands, and that is
a property of the agent side.

**Two synthetic buyer implementations behind one interface.** Scripted buyers
are deterministic, cost nothing, and run in CI as a regression gate. LLM buyers
cost tokens on both sides and run on demand for the real benchmark. One
interface, so a scenario is written once and run either way.

**The bench runs against a live shop, not an in-process fake.** Decided against
an in-memory gateway. See "Why not an in-process harness" below.

**The two tracks use different shops.**

*Track A runs on hoelshare / sw-ag.dev.* Its configuration is generous enough
that scenarios exercise the bands rather than bouncing off a ceiling:
`maxQuoteValueNet=500000`, `maxDiscountPercent=15`,
`counterOfferMaxPercent=25`, `validityDays=10`. The local docker shop is
explicitly rejected for the bench: its 40 EUR value ceiling escalates nearly
every seeded quote, so a bench run there would measure the ceiling and not the
strategies.

*Track B develops against the local docker `merchant-quote-shop`.* It only ever
reads decision rows, so the band configuration that disqualifies the local shop
for Track A is irrelevant to it. What it gains is the fast loop that already
exists there — `scripts/sync-to-shop.sh`, `bin/build-administration.sh`, a
screenshot against the cached Playwright Chromium, about four minutes end to
end — with no SSH, and no exposure to the remote host's habit of IP-banning
frequent connections or half-deleting `vendor/` on an admin-UI plugin update.

This split also removes the only contention between the tracks: they no longer
share a shop, so Track A's writes cannot disturb Track B's screenshots.

**No bench-data isolation.** It is a test shop. Bench rows mix with whatever
else is there, no run marker, no wipe between runs. The consequence is accepted
and stated here so it is not later mistaken for a defect: the per-strategy table
on that shop shows a blend of bench traffic and ad-hoc traffic.

**No spend ceiling in the plugin.** A token budget that aborts the matrix was
considered and cut. Spend is bounded outside the code, by a limit configured on
the OpenRouter account the shop's key belongs to. That is a better place for it
than a budget we enforce ourselves — it cannot be bypassed by a harness bug, and
it holds for every caller of that key rather than only for the bench.

It does not, however, bound *rounds*. A non-converging scenario would burn the
account limit rather than run forever, which is a cheaper failure but still a
failure. See "The round cap does not exist" — that finding is what makes the
bench's own `maxRounds` mandatory regardless of where the money stops.

## Finding: the round cap does not exist

Issue #142 ("No spend ceiling and no round cap before the 10,000-negotiation
run") reads CLOSED, and a branch `feat/142-round-cap-and-spend-ceiling`
implements a fifteen-pass cap. **It never landed.** PR #148 was closed
unmerged, the branch is not an ancestor of `main`, and `grep roundCap src/` on
`main` returns nothing.

So on `main` today: a buyer who keeps commenting keeps buying passes, and
nothing stops it.

Two consequences for this spec:

1. **The bench's own round bound is mandatory, not optional.** The bench drives
   the loop itself — it calls `service()` directly rather than going through
   Messenger — so its loop bound is the only thing that terminates a
   non-converging negotiation. There is no plugin-side cap underneath it. A
   scenario whose buyer never accepts is an ordinary harness bug, and an
   unbounded loop would turn that bug into an unbounded bill. `maxRounds` is a
   required field of the scenario format, with a hard default.
2. **The bench will produce evidence for #142.** Round-count distribution per
   strategy is a direct read off the bench output, and answers the question
   #142 could only guess at: how many rounds a real negotiation actually takes,
   and therefore where a cap belongs. Reopening #142 with that evidence is a
   natural follow-up, not part of this work.

## Why not an in-process harness

The rejected alternative: a `BenchQuoteGateway` implementing
`QuoteGatewayInterface` in memory, applying offers and recomputing totals, with
no shop and no database. It would be faster, free, and runnable in CI.

It was rejected for one reason that outweighs all of that: **it would have to
reimplement the totals arithmetic**, and if that arithmetic is wrong the bench
measures our fake and not the plugin. Net/gross factors, cent rounding, and the
baseline anchoring in `QuoteBaselineLines::anchor()` are exactly the areas where
this codebase has already shipped defects — a gross figure typed in a comment
stored one tax factor too high, and a gross ask compared against a cent-rounded
net baseline escalating at exactly the band limit. A bench built on a
reimplementation of that arithmetic would be least trustworthy precisely where
it matters most.

Running against the real DAL costs wall-clock time and gets everything else
right for free.

The seam survives the decision: the runner takes a `QuoteGatewayInterface`, so
an in-process gateway remains possible later if the wall-clock cost of #21's
10,000-negotiation round makes it necessary. It is not built now.

## Shared contract between the tracks

The two tracks are built in parallel. This section is the boundary; everything
in it is fixed before either track starts, and neither track may change it
unilaterally.

**File ownership.**

- Track B owns `measures.ts` and every admin file. It adds the fifth measure
  there, and puts strategy grouping in a *new* `strategy-measures.ts` with its
  own `strategy-measures.check.mjs`, following the existing `decision.check.mjs`
  / `measures.check.mjs` house pattern.
- Track A owns everything under `tests/Integration/Bench/` and imports
  `measures.ts` read-only. **Track A never edits `measures.ts`.**

No file is written by both tracks. This is what makes parallel execution safe.

**The one ordering dependency.** The fifth measure lands in `measures.ts` with
Track B. Track A's readout therefore scores the four existing measures and picks
the fifth up for free once Track B merges — it must not define its own
token-per-negotiation function in the meantime, because two definitions of one
measure is precisely the drift this contract exists to prevent. If Track A needs
the figure before Track B lands, it waits.

**The join key.** `strategyVersionId` on `merchant_quote_agent_decision`, which
already exists and is already written by `NegotiationPipeline::answer()`. Track A
sets it by constructing `QuoteAgentSettings` with the strategy under test; Track
B groups by it.

**The attribution rule.** A quote belongs to the strategy of its **last answered
pass**. This is not arbitrary: `priceRetention` measures `netBefore` against
`latestAnswered.totalNetAfter`, so the last answered pass is exactly the one
that set the price the measure reads. A quote whose passes span strategies is
counted in its last strategy's group and reported as mixed, never silently
dropped.

**Rows roll up to the strategy, not the version** (decided 2026-09-17, after the
table was first built). Attribution resolves to a strategy *version* — that is
the audit-level fact, and it is what every decision row stores — but the table
groups those versions back up to their strategy.

The defect that forced the decision: rows were keyed by `strategyVersionId` and
labelled with the strategy's name. With one version per strategy that is
invisible. `StrategyVersion`'s contract is that editing a strategy **appends** a
version and never rewrites one, so the first prompt edit produces two rows both
labelled "Margin defender", indistinguishable, each holding a fraction of the
data.

Rolling up rather than splitting is the honest choice for this surface. A
version is a prompt edit within one posture, and the question the table answers
is which posture to run. Splitting divides an N that #99 already warns is too
small to call a winner on — a strategy with six quotes across three versions
gives two per version, and a selector over that would let a merchant slice their
way to a confidently meaningless number. The spread is shown on the row instead,
so mixing is visible rather than silent.

Version-level comparison is still real; it belongs where the volume supports it,
which is the bench readout over hundreds of negotiations, not a merchant's
dashboard. So: **version is the audit key, strategy is the reporting key.**

One consequence: `mixedQuotes` counts a quote whose passes span two
*strategies*, not two versions. A quote that ran v1 then v2 of the same strategy
is not contamination of a strategy comparison, and flagging it would cry wolf.

## Track A — the bench

### Where it lives

An env-gated integration test under `tests/Integration/Bench/`, run through the
existing `scripts/test-integration.sh`. This is the established house pattern
for expensive live work: `LiveModelSmokeTest` and `LiveHistoryMessageTest` both
skip unless their environment variables are set, and both state in their
docblocks that they cost real money and must never gate CI.

Rejected alternatives: a console command in `src/Command/` would ship bench code
to every merchant for no merchant benefit; a standalone script would duplicate
the kernel bootstrap that `IntegrationTestCase` already does.

### Wiring

`PipelineFixture::pipelineWithSpy()` with exactly two substitutions:

- `ScriptedClient` becomes a real `ModelPlatform` over the model under test.
- `enabledSettings()` becomes settings built per matrix cell, carrying that
  cell's `strategyPrompt`, `strategyVersionId` and `ModelAccess`.

Everything else stays real and is resolved from the container: the DAL-backed
gateway, `OfferAuthorizer`, `OfferVerifier`, `NegotiationDecider`,
`QuoteEscalator`, `DecisionRecorder`.

`QuoteAgentSettings` already carries `strategyPrompt` and `strategyVersionId` as
constructor parameters, so no configuration is written to run a cell. That
matters: a bench that mutated `system_config` per cell would race any other
session on the shop and would leave the shop misconfigured if it crashed.

**The pipeline is called directly, not dispatched through Messenger.**
Deliberate. Going through `QuoteServicingTrigger` and the worker would add a
per-quote lock, a fingerprint check and an async hop, each of which can reorder
or drop a pass — and the bench needs a pass per round, in order, attributable to
its cell. The servicing layer is tested elsewhere; the bench is measuring
negotiation quality, not delivery.

### The loop

```
build quote from scenario
  └─ writeBuyerComment(opening ask)
       └─ pipeline->service()          # agent writes offer + reply to the real quote
            └─ buyer.respond(before, after, agentReply)
                 ├─ accept  → terminal, record
                 ├─ walk    → terminal, record
                 └─ counter → writeBuyerComment(counter), loop
```

Terminates on accept, walk, escalation, or `maxRounds`. `maxRounds` is required
per the finding above.

Three of the four steps already exist. `PipelineFixture::writeBuyerComment()`
writes a real buyer comment with the correct customer attribution and the
`SKIP_TRIGGER_FLOW` state. `PipelineFixture::agentCommentsAdded()` returns what
*this* pass said, as opposed to what the quote already said — the distinction
matters, because a reused quote carries agent comments from an earlier life.
`HistoryInjectionFixture::freshHistoryQuote()` is the precedent for building a
quote programmatically rather than picking one off the shop.

### New code

1. **`SyntheticBuyer`** — one method,
   `respond(QuoteSnapshot $before, QuoteSnapshot $after, string $agentReply): BuyerMove`,
   where `BuyerMove` is accept, walk, or counter carrying the comment text.
2. **`ScriptedBuyer`** — rules over the numbers, e.g. *counter at 60% of the
   remaining gap, accept below target, walk after N unproductive rounds*. Zero
   tokens, deterministic, seeded. This is the CI regression gate: it can assert
   that a scenario still reaches the band it used to reach, with no provider
   involved.
3. **`LlmBuyer`** — a persona system prompt over `ModelPlatform`, answering
   under a generated schema the way the agent side already does via
   `ModelPlatform::object()`. On-demand only.
4. **The scenario format** — JSON. Line items with quantities and prices, the
   opening ask, the persona, `maxRounds`, and the band the scenario is expected
   to land in. This format is a deliverable in its own right: #22 requires that
   the same format feed the nightly replay loop, and #21 requires it be agreed
   with Juan's buyer agent. Designing it once, here, is the point.
5. **The matrix driver** — iterate `scenarios × strategies × models`, stamp a
   run identifier into the run's own output (not into shop data — no isolation
   was chosen), and emit a readout.

### Scenarios to seed

Chosen from failure classes this codebase has actually shipped, not invented:

- A plain percentage price ask inside the band.
- A structured per-line `requested_price` with no comment at all — the path that
  once recorded a real price ask as `nothing_to_do`.
- A gross figure typed in a comment, which is stored one tax factor higher than
  the buyer meant.
- An ask sitting exactly on the band ceiling, where a gross ask against a
  cent-rounded net baseline beats `Epsilon::RATE`.
- Multi-round anchoring: by round five the concession must still be measured
  against the original baseline, never the previous round's reduced price.
- A bundle ask and a payment-term ask — non-price asks the agent has no mandate
  over.
- An ambiguous ask that should draw a clarification rather than an offer.
- A hostile buyer attempting to extract account history or move a cap. The
  reply must not leak; `RewordingGuard` and the authorizer are the things under
  test.

### Output

Real `merchant_quote_agent_decision` rows on the shop, which Track B's admin
table displays. Plus a JSONL readout per run for the model axis, scored by a
node script that **imports `measures.ts` directly** rather than reimplementing
the measures in PHP. That import is what makes "aligned metrics" structural
instead of a convention that drifts — the bench and the admin compute the same
numbers because they run the same code. The existing `quality:admin` scripts
already prove `node --experimental-strip-types` runs these modules headless.

## Track B — per-strategy KPIs in the admin

### What it adds

1. **The fifth measure** — `tokensPerNegotiation()` in `measures.ts`, summing
   `promptTokens + completionTokens` across a quote's passes and averaging over
   quotes. Null-tolerant like every other measure in that file: null when
   nothing was measured, never a confident zero.
2. **Strategy grouping** — a new `strategy-measures.ts` that partitions folded
   quotes by the attribution rule above and calls the same five measure
   functions per group. It imports from `measures.ts`; it does not copy from it.
3. **Strategy names** — `strategyVersionId` resolves through
   `merchant_quote_agent_strategy_version` to
   `merchant_quote_agent_strategy` for a display name. Both are plain UUID
   columns rather than DAL associations, so this is two reads, matching how
   `StrategyVersion` documents its own deliberate lack of an association.
4. **The comparison table** on `merchant-quote-agent-list`, one row per
   strategy, one column per measure.
5. **`strategy-measures.check.mjs`**, following `measures.check.mjs`, wired into
   `composer run quality:admin`.

### Developing it: the local shop has nothing to group by

Measured on `merchant-quote-shop`, 2026-09-17:

```
total decision rows                  9
rows with strategy_version_id        0
distinct quotes                      4
rows with token counts               7
seeded strategies / versions         3 / 3
```

Every built-in strategy and version row exists, but **not one decision row
carries a `strategy_version_id`** — the column post-dates the rows. A grouped
table built against that shop would render one group called "unattributed" and
prove nothing.

Two consequences:

1. **Most of Track B needs no shop at all.** `strategy-measures.ts` is pure
   functions over arrays, and `strategy-measures.check.mjs` tests them against
   fixture arrays, the way `measures.check.mjs` and `decision.check.mjs` already
   do with plain `node` and no test runner. The grouping rule, the null
   handling, the mixed-strategy count and the fifth measure are all verifiable
   with no database in the loop. This is the bulk of the work and it should be
   written first, offline.
2. **Rendering needs seeded rows.** A `scripts/seed-decisions.php`, following
   the existing `scripts/seed-order-history.php`, writes synthetic decision rows
   across the three built-in strategy versions with varied outcomes, discounts,
   token counts and terminal states. Its only purpose is to make the page
   look at something; it is dev tooling, not a fixture the tests depend on.

Verification order for Track B: fixtures offline, then the seeded local shop for
rendering, then — once Track A has produced real rows — a confirming look at
hoelshare. The third step is what proves the table works on rows the plugin
actually wrote, rather than on rows a seeder invented.

One trap the seeder must respect: this table is append-only and nobody backfills
it, so it already holds rows in shapes the current PHP no longer writes — an
`outcome` of `replied` is sitting in the local shop right now, and no
`NegotiationOutcome` case emits it. The admin module has shipped blank cells
twice from exactly this drift. Seeded rows must use the values the current enums
emit, and the grouping must not assume every row is well-formed.

### Honesty constraints

These are requirements, not polish.

**The table refuses to declare a winner.** #99 makes the point directly: B2B
quote volume is small, a shop doing a handful of quotes a week will not reach
significance in a quarter, and dressing 6 vs 4 up as a result is the failure
mode. The table shows N alongside every figure and names no best strategy.

**Null, never zero.** The existing file's rule, restated because a grouped view
multiplies the opportunities to break it: a strategy with no measurable quotes
shows an absent figure, not 0%.

**Mixed-strategy quotes are visible.** Counted in their last strategy's group
per the attribution rule, and reported as a count so the reader can judge how
much of the comparison is contaminated.

**The baseline is shared.** `priceRetention` and `dealCycleTime` compare against
quotes the agent never touched. That baseline is a property of the shop, not of
a strategy, so every group is compared against the same one. Partitioning the
baseline per strategy would be meaningless — an untouched quote has no strategy.

## What this deliberately does not do

- **No spend ceiling, no token budget.** Cut by decision. The bench's
  `maxRounds` is the only bound, and it bounds rounds rather than money.
- **No money figure.** No price table exists. Tokens only.
- **No scoring judge over transcripts.** Considered and cut. Reply quality,
  tone, and perceived fairness are not measured; only what the decision records
  can support is.
- **No bench-data isolation on the shop.** Cut by decision.
- **No in-process gateway.** The seam is preserved, the implementation is not
  built.
- **No A/B assignment mechanism.** #99 iteration 2's *reporting* half is Track
  B; its sticky-per-quote trial assignment is not in scope. The bench sets the
  strategy per run, which needs no assignment.
- **No changes to the negotiation engine.** Not one file under `src/Negotiation`
  or `src/Policy` changes. If the bench appears to require an engine change,
  that is a finding to report, not a change to make.

## Risks

**The bench's results depend on one shop's configuration.** hoelshare's bands
are what they are; a strategy comparison there does not transfer to a shop with
different bands. Runs must record the policy they ran under.

**Provider variance is not controlled.** The same cell run twice will not
produce identical numbers. With scripted buyers the buyer side is deterministic
and only the agent varies, which narrows it. Repeat counts per cell are an
implementation decision; single-run differences between strategies must not be
reported as findings.

**No isolation means the shop's data is cumulative.** Accepted. A per-strategy
table on hoelshare is a blend, and reading it as a clean experiment would be a
mistake.

**Two of the five measures need the quote to become an order.** Measured on
`merchant-quote-shop`, 2026-09-17: the dashboard filters its quote rows to
`stateMachineState.technicalName == ORDER_PLACED`, and of the quotes that
carry decision records, **zero** have an `order_id`. Thirty-seven quotes on
that shop have orders; none of them was ever serviced by the agent.

So `splitDeals` puts nothing on the agent side, and `priceRetention` and
`dealCycleTime` report absent for every strategy — correctly, since there is
nothing to measure, but categorically rather than incidentally.

The consequence for the bench is the part worth acting on: **a bench run whose
quotes never reach `ORDER_PLACED` can never produce those two measures.** A
synthetic buyer that "accepts" has not thereby created an order — accepting is
a quote state change, and the conversion to an order is a separate step. If the
bench is meant to exercise price retention and deal cycle time at all, the loop
has to carry an accepted quote through to a placed order, and if it cannot, the
bench's readout is honestly three measures wide, not five.

This was mis-diagnosed once already. The first written explanation blamed a
missing `strategy_version_id` link, which would have sent someone to write a
backfill that fixes nothing.

**An unreachable model produces unattributed rows, not failed-model rows.**
`NegotiationPipeline` records the decision — and with it `strategyVersionId` —
inside `answer()`, which is reached only once the extract call has succeeded.
`ModelUnavailable` is caught upstream and escalates through `NegotiationFailure`
before `answer()` ever runs, and `recordModelCall` only fires on a call that
returned. So a pass whose model could not be reached leaves a decision row with
no strategy and no model on it.

For the bench this is an interpretation hazard rather than a bug: if one model
in the matrix is unreachable, its cells do not appear as that model performing
badly — they vanish into the "Unattributed" group, and the comparison silently
loses a column. A run must therefore report its failed cells separately from
its measured ones, and a reader must not treat "Unattributed" as a strategy.

**Track A's shop is remote.** hoelshare has bitten before: frequent SSH triggers
IP-bans on the sibling legacy host, and an admin-UI plugin update there has
half-deleted `vendor/` and 500'd the whole shop. The bench syncs through
`scripts/test-integration.sh`, which is the path already known to work.

**Track B's shop is shared.** `merchant-quote-shop` takes syncs from every
session working in this repo, and the compiled administration bundle is never
committed. A page that looks stale usually means another session synced over it,
or `bin/build-administration.sh` was not run after the sync — re-sync and
rebuild before concluding a rendering bug is real.

**Track B's seeded rows are invented.** A table that looks right over seeded
data has only been shown to render, not to be correct on production shapes. The
confirming pass against hoelshare's real rows is what closes that, and it cannot
happen until Track A has run.
