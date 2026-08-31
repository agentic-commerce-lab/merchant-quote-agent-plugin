# Quote Agent admin page — design

**Issue:** [#7](https://github.com/agentic-commerce-lab/merchant-quote-agent-plugin/issues/7) (2.3 Monitoring)
**Date:** 2026-08-31
**Depends on:** #19 (the audit entity and its write path, merged in #37)
**Absorbs:** [#36](https://github.com/agentic-commerce-lab/merchant-quote-agent-plugin/issues/36) — its reflection guard is folded in rather than written twice
**Related:** #21 reads the same figures; #34 exports the same records

## Why this exists

The issue's own framing: *"A merchant will not raise the monetary ceiling without seeing what the agent has been doing."* The audit records exist as of #19; there is no merchant-facing view of any of them. This is the one admin surface the plugin genuinely has to build, because no generic Shopware view produces share-of-RFQs-handled.

The driver is trust, not the internal test round. The 10,000-negotiation run may not happen, and nothing here is designed around its volume.

## Scope

One module, two views: a decisions list carrying the aggregate figures, and a per-quote decision trail. Plus the integrity work that makes the trail worth trusting.

**Deliberately deferred: an in-module settings area.** The original intent was one "Quote Agent" module holding the listing, the detail *and* a settings area for limits and the custom prompt, mirroring the App version's "Quote Agent Limits" screen. `config.xml` from #5 already defines all 20 fields across 6 cards including `negotiationStrategy`, and Shopware renders them at Settings → Extensions → Configure with the sales-channel switcher. A settings view here would be a better-placed door into a furnished room, while the monitoring surface does not exist at all.

It stays cheap to add: `sw-system-config` renders a config domain inside any page (props verified on the running shop: `domain`, `salesChannelId`, `salesChannelSwitchable`, `inherit`), so it is one route, one view and a snippet block, with `config.xml` remaining the single source of truth. This design registers a **module with routes** rather than a standalone page specifically so that slots in without reshaping anything. Recorded on #7.

## Approach

The entity from #19 already has full admin API routes, generated automatically — verified with `debug:router` on the running shop:

```
api.merchant_quote_agent_decision.search      POST  /api/search/merchant-quote-agent-decision
api.merchant_quote_agent_decision.aggregate   POST  /api/aggregate/merchant-quote-agent-decision
api.merchant_quote_agent_decision.detail      GET   /api/merchant-quote-agent-decision/{path}
```

So the page reads through `repositoryFactory` and `Criteria`, and **no PHP is written for data**. A thin controller serving pre-computed aggregates was rejected: it would be a second implementation of arithmetic the DAL already performs, free to drift from what every other consumer reports.

A console command instead of a page was also considered and rejected — cheapest by far, but a command a developer runs is not a merchant-facing surface, and the issue's purpose is a merchant watching the agent.

The PHP in this issue is the `Protection` attributes and the tests.

## The module

`src/Resources/app/administration/`, following the structure SwagCommercial uses:

```
src/
  main.ts
  module/merchant-quote-agent/
    index.ts                                    module + privilege registration
    acl/index.ts                                privilege mapping
    page/merchant-quote-agent-list/             index.ts + .html.twig
    page/merchant-quote-agent-detail/           index.ts + .html.twig
    snippet/en.json
    snippet/de.json
```

Registered as `merchant-quote-agent` with `entity: 'merchant_quote_agent_decision'`, giving routes `merchant.quote.agent.index` and `merchant.quote.agent.detail/:id`.

**Placement:** a navigation entry with `parent: 'sw-order'`, `position: 30` — directly after SwagCommercial's Quotes, which registers at `parent: 'sw-order'`, `position: 20`. Not a settings item: a merchant checks this repeatedly while deciding whether to trust the agent, which is a workspace rather than a config screen.

Shopware's own `bin/build-administration.sh` compiles it. **No JavaScript toolchain enters this repository** — no `package.json`, no bundler config.

Snippet files are `en.json` and `de.json`, matching the reference module's convention. Both ship from the start: the string set is small now, and retrofitting German later means revisiting every label once the page has grown.

## ACL

Declared in TypeScript via `Shopware.Service('privileges').addPrivilegeMappingEntry()`. The API enforces from `acl_role` permissions, so no PHP registration is needed.

| Role | Privileges | Depends on |
|---|---|---|
| `merchant_quote_agent.viewer` | `merchant_quote_agent_decision:read` | — |
| `merchant_quote_agent.deleter` | `merchant_quote_agent_decision:delete` | `viewer` |

No `creator`, no `editor`. Nothing should ever create or update an audit row through the admin, and omitting the roles states that more clearly than a comment. Routes carry `meta.privilege: 'merchant_quote_agent.viewer'`.

## The data

**The headline figure cannot come from the audit table alone.** It holds only quotes the agent serviced; "received" and "expired unanswered" include quotes it never touched. The denominator lives in the `quote` entity, which has its own aggregate route and exposes `stateMachineState` and `amountNet` as `ApiAware`.

| Figure | Source | Query |
|---|---|---|
| received | `quote` | count over `createdAt` range |
| auto-answered | decisions | count, `outcome IN ('offered','countered')` |
| escalated | decisions | count, `outcome = 'escalated'` |
| expired unanswered | both | see below |
| quotes handled | decisions | bucket count of the terms aggregation below |
| total quoted value handled | decisions | see below |
| average granted discount vs the band | decisions | see below |

Shares are computed against **received**.

### Total quoted value handled

A quote serviced three times has three rows, each carrying `totalNetBefore`. Summing that column reports one quote's value three times.

Instead: a `TermsAggregation` on `quoteId` with a nested `MaxAggregation` on `totalNetBefore` — one value per quote — summed client-side. `TermsAggregation`'s constructor accepts a nested `?Aggregation` and an optional `?int $limit`; the limit is **not** used, because truncating buckets would silently understate the sum rather than approximate it. Volume is constrained by the time range instead.

The bucket count of that same aggregation is **quotes handled**, the numerator of share-of-RFQs-handled.

### Average granted discount against the band

The DAL cannot divide one aggregate by another, and no per-row ratio is stored. Two averages are shown together — "granted 6.2% against a 10.0% cap":

- `AvgAggregation` on `discountPercentGranted`
- `AvgAggregation` on `maxDiscountPercent`

**Both filtered to `outcome IN ('offered','countered')`.** `discountPercentGranted` is measured from the database rather than the offer's intent — deliberately, since a per-line offer carries no `discountPercent` of its own — so a verification-failed pass carries a real granted discount because the write happened and the database shows the reduction. Those rows are `escalated`. Averaging them in would mix discounts the agent stood behind with ones it applied and then escalated over.

### Expired unanswered

Defined precisely as: **quotes in the `expired` state within the range that have no decision record with `outcome IN ('offered','countered')`.**

Two queries, subtracted client-side:

1. `quote` ids where `stateMachineState.technicalName = 'expired'` in range
2. distinct `quoteId` from decisions with `outcome IN ('offered','countered')` in range, via a terms aggregation

A cross-entity join is not available, and a quote can hold a `nothing_to_do` record while still never having been answered — which is why the definition keys on the outcome rather than on a record existing.

## The views

**List** — `sw-entity-listing` over the entity, with the aggregate figures above it. Columns: created, quote number, outcome, band, granted discount, duration, escalation reason. Sorting and paging are Shopware's. The time range is a `RangeFilter` on `createdAt` with presets of 7, 30 and 90 days, defaulting to 30.

**Detail** — the trail as sections, not a field dump, since the issue explicitly rejects a log dump:

- **What the buyer asked** — `interpretedAsks`
- **What policy allowed** — `band`, `maxDiscountPercent`, `escalationReason`. There is no separate value-ceiling column: a ceiling refusal surfaces as `escalationReason = 'quote_value_limit_exceeded'`, and the verifier's objections are in `violations`.
- **What was offered** — `totalNetBefore` → `totalNetAfter`, `discountPercentGranted`, `writes`, `authorized`, `verified`, `violations`
- **What the model was told and cost** — `model`, `modelHost`, the three prompt hashes, `promptTokens`, `completionTokens`, `modelLatencyMs`
- **What the buyer was told** — `buyerComment`, verbatim
- **What went wrong** — `errorClass`, `errorChain`

Four columns are JSON — `interpretedAsks`, `violations`, `writes`, `errorChain` — and get light structured rendering rather than a pretty-printed blob.

Two fields need their real meaning surfaced in the UI, because both mislead a reader who assumes the obvious:

- **`modelLatencyMs` excludes a failed retry attempt's time.** Label it model time and show `durationMs` as the pass's own clock.
- **`attempt` is the crash-budget counter at pass start, not a delivery number.** A thrown-and-redelivered pass records `0` again. Either label it accordingly or omit it from the list; do not present it as "retry number".

## Integrity

Two mechanisms covering different things; neither is complete alone.

**`#[Protection(write: [Protection::SYSTEM_SCOPE])]` on every field.** `Protection` compiles to `WriteProtected`, whose constructor takes the scopes that *are* allowed to write. `Context::createDefaultContext()` — what `DecisionRecordWriter` uses — runs in `system` scope, while admin-API requests run in `user`/`crud`. So this blocks `PATCH` and `POST` through the API while leaving the plugin's own write path untouched. The existing integration tests all write through `createDefaultContext()` and are unaffected.

It goes on all 36 fields including `id`. Miss one and that field stays patchable; a uniform rule has no judgment calls in it.

**It does not cover `DELETE`.** `WriteProtected` is enforced in `WriteCommandExtractor` during field encoding, and a delete never encodes fields. Deletion is therefore an ACL matter: only the explicit `deleter` role grants it, and `viewer` does not.

**The honest limit:** ACL constrains role-limited users, not omnipotent ones. An admin with full entity rights, or an integration with a broad API key, can still delete rows. This makes the trail resistant to casual and accidental modification, not tamper-proof against someone who owns the shop. That is the right ceiling for a merchant's own database.

Explicitly not proposed: database-level append-only enforcement, and hash-chaining of records. Both are real techniques and both are disproportionate here. #20 covers signing, and that is about outgoing messages rather than the trail's own integrity.

## Testing

**PHP carries the contracts.**

- **A reflection guard over every field** asserting each declares both `api: ['admin-api' => true, 'store-api' => false]` and `Protection(write: [SYSTEM_SCOPE])`. This absorbs #36. Both attributes fail silently and permissively when mistyped — an unrecognised `api` key yields an empty `ApiAware` source list, which Shopware expands to *both* APIs — which is exactly what a reflection test is for. Prove it red by misspelling one key.
- **A scope-rejection integration test**, in the same new class. `Context::scope(Context::USER_SCOPE, ...)` attempts a write as an admin would; assert it is rejected, and that the same write in system scope succeeds. This proves the protection behaviourally and proves the plugin's own writer still works.
- **Aggregation-contract tests** in a **new** `tests/Integration/DecisionRecordGuardsTest.php`, not in `DecisionRecordTest` — measured: that class sits at 6 own methods plus 3 from the `PipelineFixture` trait, and trait methods count toward `too-many-methods`, so it has one slot left against a cap of 10. The new class holds the scope-rejection case and the aggregation contracts together, which is a coherent split anyway: `DecisionRecordTest` proves the record round-trips, the new one proves the guards and the figures. They cover the exact queries the page runs: the nested `terms(quoteId)` + `max(totalNetBefore)`, and both averages under the `outcome` filter. Task 10 proved the column types aggregate; these pin the specific shapes and their arithmetic, so the figures shown have a server-side test behind them.

**TypeScript is the honest gap.** `mago` reads only `src/`, so no existing gate sees a Vue component.

- **ESLint via the shop's own config**, run through `scripts/lint-administration.sh` mirroring the existing `scripts/test-integration.sh`. The repository already accepts that some checks run only inside the container, so this is a precedent rather than a new pattern.
- **No jest unit tests.** Testing a Shopware admin component is largely asserting against mocks of Shopware's own services; the yield is low and the setup cost is real. The mitigation is architectural: keep components declarative and push every decision into the criteria, which the PHP tests pin.

**What remains unguarded, stated rather than implied:** nothing enforces that the TypeScript builds *the same* criteria the PHP tests verify. If the page's figures ever disagree with a direct query, that is the seam.

## Deliverables

**Create:** the module tree above (`main.ts`, `index.ts`, `acl/index.ts`, two page components with templates, two snippet files); `scripts/lint-administration.sh`; `tests/Unit/Audit/RecordFieldGuardsTest.php`; `tests/Integration/DecisionRecordGuardsTest.php`.

**Modify:** `src/Audit/QuoteDecisionRecord.php` — 36 `#[Protection]` attributes.

## Out of scope

| Deferred | Home |
|---|---|
| In-module settings area for limits and the custom prompt | recorded on #7; cheap via `sw-system-config` |
| Anonymised JSONL export | #34 |
| Terminal outcome per decision | #33 |
| Records for passes refused before the pipeline runs | #35 |
| Signing outgoing messages | #20 |
| Any dashboard beyond one list and one detail | not planned — the issue says "one page, not a dashboard" |

## Global constraints

- PHP 8.3, Shopware 6.7, `declare(strict_types=1)` in every PHP file.
- Gates enforced by `composer run quality`, which must exit 0: cyclomatic complexity 10 **class-scoped**, `excessive-parameter-list` 5, `too-many-methods` **10**, `too-many-properties` **10**, `excessive-nesting` 4, 400 physical lines per file.
- `QuoteDecisionRecord` carries a sanctioned `@mago-expect lint:too-many-properties`; adding `Protection` attributes must not introduce another suppression.
- Commits are signed. Never bypass signing.
- The audit entity is admin-API only and must never be exposed to the Store API.
