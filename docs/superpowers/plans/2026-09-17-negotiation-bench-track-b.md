# Track B: per-strategy KPIs in the admin — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Group the dashboard's existing success measures by negotiation strategy, and add tokens per negotiation as a fifth measure, so a merchant can compare strategies in the admin.

**Architecture:** Five pure functions over arrays in a new `strategy-measures.ts` that imports — never copies — the four existing measures from `measures.ts`. Tested by a `strategy-measures.check.mjs` running under plain `node`, matching the two check scripts already in the module. The list page renders a comparison table from a computed property; no new backend, no new entity, no migration.

**Tech Stack:** TypeScript (no build step of its own — Shopware's administration bundle compiles it), `node --experimental-strip-types` for the checks, Vue 2 options API + Twig templates as used by the existing module pages.

**Spec:** `docs/superpowers/specs/2026-09-17-negotiation-bench-design.md`

## Global Constraints

- **Never edit `measures.ts` beyond adding `tokensPerNegotiation`.** Track A imports that file read-only and runs in parallel. Adding the one exported function is expected; changing any existing function's signature is not.
- **Never touch `src/Negotiation/`, `src/Policy/`, or anything under `tests/Integration/`.** Those are Track A's and the engine's. If this work appears to require an engine change, stop and report it.
- **Null, never zero.** Every measure returns `null` when it has nothing to measure. A merchant without `quote:read` must see a figure absent, not a confident zero, and so must a strategy with no quotes.
- **No winner is declared.** The table shows N alongside every figure and names no best strategy. B2B quote volume does not reach significance; per #99, dressing 6 vs 4 up as a result is the failure being avoided.
- **Decisions arrive newest-first.** `loadPasses()` sorts `createdAt DESC`, and `foldToQuotes` depends on it. Any new fold must assume the same order.
- **This table is append-only and nobody backfills it.** It holds rows whose shapes the current PHP no longer writes — an `outcome` of `replied` is in the local shop right now. Never assume a row is well-formed.
- **Verify rendering, don't infer it.** Reading the Twig proves nothing. The loop is `./scripts/sync-to-shop.sh`, then `docker exec merchant-quote-shop sh -c 'cd /var/www/html && bin/build-administration.sh'` (**required after every sync** — the bundle is never committed), then a screenshot. Shop: `http://localhost:8095/admin`, login `admin` / `shopware`.
- Run `composer run quality:admin` before every commit that touches the module.

---

### Task 1: Tokens per negotiation

**Files:**
- Modify: `src/Resources/app/administration/src/module/merchant-quote-agent/measures.ts`
- Test: `src/Resources/app/administration/src/module/merchant-quote-agent/measures.check.mjs`

**Interfaces:**
- Consumes: nothing from other tasks.
- Produces: `tokensPerNegotiation(passes: any[]): { meanTokens: number | null; measured: number; quotes: number }` — exported from `measures.ts`. Task 2 and Task 4 both call it.

**Why it takes raw passes, not folded quotes.** `foldToQuotes()` keeps only `latest` and `latestAnswered` per quote; the token counts on every *other* pass are discarded. A measure summing a negotiation's full cost must therefore fold the raw array itself. `escalationResolution(passes, slaHours)` already takes raw passes for a comparable reason, so this is the established shape in this file, not a new one.

- [ ] **Step 1: Write the failing assertions**

Append to `measures.check.mjs`, before the formatting section. Add `tokensPerNegotiation` to the existing import list from `./measures.ts`.

```js
// ------------------------------------------------------------ tokens per negotiation

// Every pass of a quote counts, not just the latest: the cost of a
// negotiation is what all its rounds cost together.
assert.deepEqual(
    tokensPerNegotiation([
        { quoteId: 'q1', promptTokens: 100, completionTokens: 50 },
        { quoteId: 'q1', promptTokens: 200, completionTokens: 100 },
        { quoteId: 'q2', promptTokens: 400, completionTokens: 50 },
    ]),
    { meanTokens: 450, measured: 3, quotes: 2 },
);

// A provider that sends no `usage` block leaves nulls. The pass still
// happened, so the quote is still measured — its unknown passes contribute
// nothing rather than poisoning the sum.
assert.deepEqual(
    tokensPerNegotiation([
        { quoteId: 'q1', promptTokens: 100, completionTokens: null },
        { quoteId: 'q1', promptTokens: null, completionTokens: null },
    ]),
    { meanTokens: 100, measured: 1, quotes: 1 },
);

// A quote whose every pass is unmeasured is not a zero-cost quote.
assert.deepEqual(
    tokensPerNegotiation([{ quoteId: 'q1', promptTokens: null, completionTokens: null }]),
    { meanTokens: null, measured: 0, quotes: 0 },
);

assert.deepEqual(tokensPerNegotiation([]), { meanTokens: null, measured: 0, quotes: 0 });
```

- [ ] **Step 2: Run to verify it fails**

```bash
node src/Resources/app/administration/src/module/merchant-quote-agent/measures.check.mjs
```

Expected: fails with `SyntaxError` or `TypeError: tokensPerNegotiation is not a function`.

- [ ] **Step 3: Implement**

Append to `measures.ts`:

```ts
/**
 * What one negotiation cost in tokens, averaged over the negotiations we could
 * measure.
 *
 * Takes raw passes rather than folded quotes because `foldToQuotes()` keeps
 * only `latest` and `latestAnswered`, discarding the token counts on every
 * other round — and a negotiation's cost is what all its rounds cost together.
 * `escalationResolution` takes raw passes for a comparable reason.
 *
 * Not every OpenAI-compatible provider sends a `usage` block, so a pass can be
 * unmeasured. An unmeasured pass contributes nothing to its quote's sum; a
 * quote whose passes are ALL unmeasured is dropped entirely rather than
 * reported as having cost zero.
 *
 * Deliberately not money: the plugin records `model` and `modelHost` but
 * carries no price table, and a currency figure derived from rates we do not
 * have would be invented.
 */
export function tokensPerNegotiation(passes: any[]): {
    meanTokens: number | null;
    measured: number;
    quotes: number;
} {
    const byQuote = new Map<string, number>();
    let measured = 0;

    (passes ?? []).forEach((pass) => {
        const prompt = Number(pass.promptTokens ?? 0);
        const completion = Number(pass.completionTokens ?? 0);

        if (pass.promptTokens === null || pass.promptTokens === undefined) {
            if (pass.completionTokens === null || pass.completionTokens === undefined) {
                return;
            }
        }

        measured += 1;
        byQuote.set(pass.quoteId, (byQuote.get(pass.quoteId) ?? 0) + prompt + completion);
    });

    return {
        meanTokens: mean([...byQuote.values()]),
        measured,
        quotes: byQuote.size,
    };
}
```

`mean()` is already defined in this file and is null-tolerant; reuse it rather than adding a second averaging helper.

- [ ] **Step 4: Run to verify it passes**

```bash
node src/Resources/app/administration/src/module/merchant-quote-agent/measures.check.mjs
```

Expected: `measures.check.mjs: all assertions passed`

- [ ] **Step 5: Commit**

```bash
git add src/Resources/app/administration/src/module/merchant-quote-agent/measures.ts src/Resources/app/administration/src/module/merchant-quote-agent/measures.check.mjs
git commit -m "feat(admin): measure what one negotiation costs in tokens

Takes raw passes rather than folded quotes, because foldToQuotes keeps
only the latest and latest-answered pass and a negotiation's cost is what
all its rounds cost together. An unmeasured pass contributes nothing; a
quote with no measured pass is dropped rather than read as free.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 2: Strategy attribution and grouping

**Files:**
- Create: `src/Resources/app/administration/src/module/merchant-quote-agent/strategy-measures.ts`
- Test: `src/Resources/app/administration/src/module/merchant-quote-agent/strategy-measures.check.mjs`

**Interfaces:**
- Consumes: `tokensPerNegotiation` from Task 1; `autoExecutionRate`, `escalationResolution`, `priceRetention`, `dealCycleTime`, `splitDeals` from `measures.ts`; `foldToQuotes` from `decision.ts`.
- Produces:
  - `attributeStrategy(quotePasses: any[]): { strategyVersionId: string | null; mixed: boolean }`
  - `groupPassesByStrategy(passes: any[]): Map<string | null, any[]>`
  - `strategyRows(passes, quoteRows, orderDates, slaHours, nameFor): any[]` — Task 4 renders these.

**The attribution rule, and the trap in it.** The spec says a quote belongs to the strategy of its **last answered pass**, because `priceRetention` measures `netBefore` against `latestAnswered.totalNetAfter` — the last answered pass is the one that set the price being measured.

But a quote that only ever escalated has **no** answered pass. Attributing it to nothing would drop every escalation-only quote out of every group, and `autoExecutionRate` would then report every strategy as 100% auto-executing — precisely the failure its own docblock in `measures.ts` warns about. So the rule falls back to the newest pass of any kind. Write it as: newest answered pass, else newest pass, else null.

- [ ] **Step 1: Write the failing test file**

Create `strategy-measures.check.mjs`:

```js
/**
 * Self-check for strategy-measures.ts. Same arrangement as
 * measures.check.mjs and decision.check.mjs — no test runner, because the
 * project has no JS toolchain and these are pure functions.
 *
 *     node src/Resources/app/administration/src/module/merchant-quote-agent/strategy-measures.check.mjs
 */

import assert from 'node:assert/strict';
import { attributeStrategy, groupPassesByStrategy, strategyRows } from './strategy-measures.ts';

const iso = (day, hour = 0) => `2026-09-${String(day).padStart(2, '0')}T${String(hour).padStart(2, '0')}:00:00.000+00:00`;

// ------------------------------------------------------------------ attribution

// Passes arrive newest-first, so the first answered one is the last answered
// one. It is the pass that set the price priceRetention measures.
assert.deepEqual(
    attributeStrategy([
        { outcome: 'escalated', strategyVersionId: 'v2', createdAt: iso(3) },
        { outcome: 'offered', strategyVersionId: 'v1', createdAt: iso(2) },
    ]),
    { strategyVersionId: 'v1', mixed: true },
);

// A quote that only ever escalated has no answered pass. Dropping it would
// remove every escalation from every group, and auto-execution would then
// read 100% for every strategy.
assert.deepEqual(
    attributeStrategy([{ outcome: 'escalated', strategyVersionId: 'v2', createdAt: iso(3) }]),
    { strategyVersionId: 'v2', mixed: false },
);

// One strategy throughout is not mixed.
assert.deepEqual(
    attributeStrategy([
        { outcome: 'offered', strategyVersionId: 'v1', createdAt: iso(2) },
        { outcome: 'offered', strategyVersionId: 'v1', createdAt: iso(1) },
    ]),
    { strategyVersionId: 'v1', mixed: false },
);

// Rows predating the column carry no strategy at all. They group under null
// rather than being discarded.
assert.deepEqual(
    attributeStrategy([{ outcome: 'offered', strategyVersionId: null, createdAt: iso(1) }]),
    { strategyVersionId: null, mixed: false },
);

// A null alongside a real id is not a second strategy.
assert.deepEqual(
    attributeStrategy([
        { outcome: 'offered', strategyVersionId: 'v1', createdAt: iso(2) },
        { outcome: 'nothing_to_do', strategyVersionId: null, createdAt: iso(1) },
    ]),
    { strategyVersionId: 'v1', mixed: false },
);

assert.deepEqual(attributeStrategy([]), { strategyVersionId: null, mixed: false });

// -------------------------------------------------------------------- grouping

const passes = [
    { id: 'p1', quoteId: 'q1', outcome: 'offered', strategyVersionId: 'v1', createdAt: iso(2), totalNetBefore: 1000, totalNetAfter: 900, promptTokens: 100, completionTokens: 50 },
    { id: 'p2', quoteId: 'q2', outcome: 'escalated', strategyVersionId: 'v2', createdAt: iso(2), totalNetBefore: 2000, promptTokens: 80, completionTokens: 20 },
    { id: 'p3', quoteId: 'q3', outcome: 'offered', strategyVersionId: 'v1', createdAt: iso(1), totalNetBefore: 500, totalNetAfter: 475, promptTokens: 60, completionTokens: 40 },
];

const grouped = groupPassesByStrategy(passes);

assert.deepEqual([...grouped.keys()].sort(), ['v1', 'v2']);
assert.deepEqual(grouped.get('v1').map((p) => p.id), ['p1', 'p3']);
assert.deepEqual(grouped.get('v2').map((p) => p.id), ['p2']);

// Every pass of a quote travels with its quote's group, not with its own
// strategy — otherwise a quote's rounds would be split across groups and each
// group would measure a fragment of a negotiation.
assert.deepEqual(
    [...groupPassesByStrategy([
        { id: 'a', quoteId: 'q9', outcome: 'escalated', strategyVersionId: 'v2', createdAt: iso(3) },
        { id: 'b', quoteId: 'q9', outcome: 'offered', strategyVersionId: 'v1', createdAt: iso(2) },
    ]).entries()].map(([key, group]) => [key, group.map((p) => p.id)]),
    [['v1', ['a', 'b']]],
);

// ------------------------------------------------------------------------ rows

const quoteRows = [
    { id: 'q1', amountNet: 900, requestedAt: iso(2), createdAt: iso(2), orderId: 'o1', totalDiscount: 0, totalLineItemDiscount: 100 },
    { id: 'q2', amountNet: 2000, requestedAt: iso(2), createdAt: iso(2), orderId: null, totalDiscount: 0, totalLineItemDiscount: 0 },
    { id: 'q3', amountNet: 475, requestedAt: iso(1), createdAt: iso(1), orderId: 'o2', totalDiscount: 0, totalLineItemDiscount: 25 },
];
const orderDates = new Map([['o1', iso(3)], ['o2', iso(2)]]);
const nameFor = (id) => ({ v1: 'Margin defender', v2: 'Fast close' })[id] ?? null;

const rows = strategyRows(passes, quoteRows, orderDates, null, nameFor);

assert.deepEqual(rows.map((r) => r.name), ['Margin defender', 'Fast close']);

const marginDefender = rows[0];
// Two quotes, neither escalated.
assert.equal(marginDefender.quotes, 2);
assert.equal(marginDefender.autoExecution.rate, 100);
assert.equal(marginDefender.mixedQuotes, 0);
// (100+50) and (60+40) over two quotes.
assert.equal(marginDefender.tokens.meanTokens, 125);

const fastClose = rows[1];
assert.equal(fastClose.quotes, 1);
assert.equal(fastClose.autoExecution.rate, 0);

// A strategy with no comparable baseline reports an absent figure, never 0.
assert.equal(fastClose.priceRetention.baselineDiscount, null);

// An id with no name still renders as a group; it is not dropped.
assert.equal(strategyRows(passes, quoteRows, orderDates, null, () => null)[0].name, null);

// eslint-disable-next-line no-console
console.log('strategy-measures.check.mjs: all assertions passed');
```

- [ ] **Step 2: Run to verify it fails**

```bash
node src/Resources/app/administration/src/module/merchant-quote-agent/strategy-measures.check.mjs
```

Expected: `ERR_MODULE_NOT_FOUND` for `./strategy-measures.ts`.

- [ ] **Step 3: Implement**

Create `strategy-measures.ts`:

```ts
/**
 * The dashboard's success measures, grouped by the negotiation strategy that
 * produced them.
 *
 * Every figure here comes from calling the functions in measures.ts on a
 * subset of the passes — nothing is recomputed and nothing is copied. That is
 * the point: the per-strategy table and the overall tiles agree because they
 * run the same code, and a fix to a measure fixes both.
 *
 * This file states no winner. B2B quote volume is small enough that a
 * quarter's difference between two strategies is usually noise, so every row
 * carries its N and the template shows it.
 */

import {
    autoExecutionRate,
    dealCycleTime,
    escalationResolution,
    priceRetention,
    splitDeals,
    tokensPerNegotiation,
} from './measures';
import { answeredTheBuyer, foldToQuotes } from './decision';

/**
 * Which strategy a quote belongs to, from all of that quote's passes.
 *
 * The last answered pass wins, because `priceRetention` measures `netBefore`
 * against `latestAnswered.totalNetAfter` — that pass is the one that set the
 * price being measured. Passes arrive newest-first, so the FIRST answered pass
 * in the array is the last one chronologically.
 *
 * A quote that only ever escalated has no answered pass. Falling back to the
 * newest pass of any kind is not a nicety: dropping those quotes would take
 * every escalation out of every group, and `autoExecutionRate` would then
 * report 100% for every strategy — the exact failure its own docblock exists
 * to prevent.
 *
 * `mixed` counts a quote whose passes name more than one strategy, which
 * happens when a merchant switches strategies mid-negotiation. Such a quote is
 * still attributed, never dropped, but the template reports how many there are
 * so the reader can judge how much of the comparison is contaminated.
 */
export function attributeStrategy(quotePasses: any[]): { strategyVersionId: string | null; mixed: boolean } {
    const named = (quotePasses ?? [])
        .map((pass) => pass.strategyVersionId ?? null)
        .filter((id): id is string => typeof id === 'string' && id !== '');

    const answered = (quotePasses ?? []).find((pass) => answeredTheBuyer(pass.outcome ?? null) && pass.strategyVersionId);

    return {
        strategyVersionId: answered?.strategyVersionId ?? named[0] ?? null,
        mixed: new Set(named).size > 1,
    };
}

/**
 * Every pass, bucketed by its QUOTE's strategy rather than its own.
 *
 * A quote's rounds must not be split across groups: a group holding round two
 * but not round one would measure a fragment of a negotiation and report it as
 * a whole one.
 */
export function groupPassesByStrategy(passes: any[]): Map<string | null, any[]> {
    const byQuote = new Map<string, any[]>();

    (passes ?? []).forEach((pass) => {
        const seen = byQuote.get(pass.quoteId);

        if (seen) {
            seen.push(pass);

            return;
        }

        byQuote.set(pass.quoteId, [pass]);
    });

    const grouped = new Map<string | null, any[]>();

    byQuote.forEach((quotePasses) => {
        const { strategyVersionId } = attributeStrategy(quotePasses);
        const bucket = grouped.get(strategyVersionId);

        if (bucket) {
            bucket.push(...quotePasses);

            return;
        }

        grouped.set(strategyVersionId, [...quotePasses]);
    });

    return grouped;
}

/**
 * One row per strategy, each carrying the same five measures the overall
 * tiles show, plus the counts that keep the row honest.
 *
 * The baseline is deliberately NOT partitioned. `priceRetention` and
 * `dealCycleTime` compare against quotes the agent never touched, and an
 * untouched quote has no strategy — so every group is compared against the
 * same shop-wide baseline.
 */
export function strategyRows(
    passes: any[],
    quoteRows: any[],
    orderDates: Map<string, string>,
    slaHours: number | null,
    nameFor: (strategyVersionId: string | null) => string | null,
): any[] {
    return [...groupPassesByStrategy(passes).entries()].map(([strategyVersionId, group]) => {
        const folded = foldToQuotes(group);
        const foldedByQuoteId = new Map(folded.map((quote) => [quote.quoteId, quote]));
        const { agent, baseline } = splitDeals(quoteRows ?? [], orderDates, foldedByQuoteId);

        const mixedQuotes = [...groupQuotes(group).values()].filter((quotePasses) => attributeStrategy(quotePasses).mixed)
            .length;

        return {
            strategyVersionId,
            name: nameFor(strategyVersionId),
            quotes: folded.length,
            mixedQuotes,
            autoExecution: autoExecutionRate(folded),
            escalations: escalationResolution(group, slaHours),
            priceRetention: priceRetention(agent, baseline),
            cycleTime: dealCycleTime(agent, baseline),
            tokens: tokensPerNegotiation(group),
        };
    });
}

function groupQuotes(passes: any[]): Map<string, any[]> {
    const byQuote = new Map<string, any[]>();

    passes.forEach((pass) => {
        const seen = byQuote.get(pass.quoteId);

        if (seen) {
            seen.push(pass);

            return;
        }

        byQuote.set(pass.quoteId, [pass]);
    });

    return byQuote;
}
```

Note `splitDeals` puts every quote *not* in `foldedByQuoteId` on the baseline side, which is exactly what is wanted here: within a strategy's group, the other strategies' quotes are not agent deals *for that group*, and the value-band filter in `withinRange` keeps the comparison honest.

- [ ] **Step 4: Run to verify it passes**

```bash
node src/Resources/app/administration/src/module/merchant-quote-agent/strategy-measures.check.mjs
```

Expected: `strategy-measures.check.mjs: all assertions passed`

If the two `groupQuotes`/`groupPassesByStrategy` bodies trip the duplication gate, extract the shared fold into one local helper and call it from both.

- [ ] **Step 5: Wire the check into the quality gate**

In `composer.json`, extend `quality:admin` with a third `node --experimental-strip-types` invocation for `strategy-measures.check.mjs`, matching the two already listed.

- [ ] **Step 6: Run the gate**

```bash
composer run quality:admin && composer run quality:dupes
```

Expected: all three checks print their "all assertions passed" line; jscpd reports no new duplication.

- [ ] **Step 7: Commit**

```bash
git add src/Resources/app/administration/src/module/merchant-quote-agent/strategy-measures.ts src/Resources/app/administration/src/module/merchant-quote-agent/strategy-measures.check.mjs composer.json
git commit -m "feat(admin): group the success measures by negotiation strategy

Attribution is the last answered pass, because that is the pass
priceRetention measures. A quote that only ever escalated falls back to
its newest pass — dropping those would take every escalation out of
every group and report 100% auto-execution for every strategy.

Every figure comes from calling measures.ts on a subset, so the table and
the overall tiles cannot drift apart.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 3: Resolve strategy names

**Files:**
- Modify: `src/Resources/app/administration/src/module/merchant-quote-agent/page/merchant-quote-agent-list/index.ts`

**Interfaces:**
- Consumes: nothing from other tasks.
- Produces: a `strategyNameFor(strategyVersionId: string | null): string | null` method on the page component, passed to `strategyRows()` in Task 4.

**Why two reads.** `StrategyVersion.strategyId` is a plain UUID column, not a DAL association — its own docblock says the association attributes sit outside what `CoreFloorCompatibilityTest` can check. So resolving a version id to a display name is: load the version rows, map `id → strategyId`, load the strategy rows, map `strategyId → name`.

- [ ] **Step 1: Add the repositories**

Alongside the existing `merchant_quote_agent_decision` repository getter, add getters for `merchant_quote_agent_strategy_version` and `merchant_quote_agent_strategy`, following the same `repositoryFactory.create(...)` shape already in the file.

- [ ] **Step 2: Load them in `load()`**

Add a `loadStrategies()` method, called from `load()` next to `loadPasses()`. Store `strategyVersions` and `strategies` on `data()`. Use a `Criteria(1, 500)` — a shop will not have more strategy versions than that, and an unbounded criteria on a page load is not worth it.

Guard the read the way the page already guards `quote:read`: a merchant without the strategy ACL privilege must get absent names, not a failed page.

- [ ] **Step 3: Add the resolver**

```ts
/**
 * A version id to the strategy's display name, via the version's strategyId.
 *
 * Two reads rather than an association, because StrategyVersion.strategyId is a
 * plain UUID column by design — see that entity's docblock.
 *
 * An unknown id returns null rather than a placeholder string: the template
 * decides how an unnamed group reads, and a name invented here would be
 * indistinguishable from a real one.
 */
strategyNameFor(strategyVersionId: string | null): string | null {
    if (strategyVersionId === null) {
        return null;
    }

    const version = (this.strategyVersions ?? []).find((row: any) => row.id === strategyVersionId);

    if (!version) {
        return null;
    }

    return (this.strategies ?? []).find((row: any) => row.id === version.strategyId)?.name ?? null;
},
```

- [ ] **Step 4: Verify against the real shop**

```bash
docker exec -w /var/www/html merchant-quote-shop mysql -uroot -proot -h127.0.0.1 -N -B shopware -e \
  "SELECT LOWER(HEX(v.id)), s.name FROM merchant_quote_agent_strategy_version v JOIN merchant_quote_agent_strategy s ON s.id = v.strategy_id"
```

Expected: three rows, one per built-in strategy. Confirm the ids your resolver would receive are these.

- [ ] **Step 5: Commit**

```bash
git add src/Resources/app/administration/src/module/merchant-quote-agent/page/merchant-quote-agent-list/index.ts
git commit -m "feat(admin): resolve a strategy version id to its strategy name

Two reads rather than a DAL association, because StrategyVersion.strategyId
is deliberately a plain UUID column. An unknown id resolves to null so the
template decides how an unnamed group reads.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 4: The comparison table

**Files:**
- Modify: `src/Resources/app/administration/src/module/merchant-quote-agent/page/merchant-quote-agent-list/index.ts`
- Modify: `src/Resources/app/administration/src/module/merchant-quote-agent/page/merchant-quote-agent-list/merchant-quote-agent-list.html.twig`
- Modify: `src/Resources/app/administration/src/module/merchant-quote-agent/snippet/en-GB.json` (and the other locales present)

**Interfaces:**
- Consumes: `strategyRows` (Task 2), `strategyNameFor` (Task 3), `tokensPerNegotiation` (Task 1).
- Produces: nothing downstream.

- [ ] **Step 1: Add the computed property**

```ts
strategyComparison(): any[] {
    return strategyRows(
        this.currentPasses,
        this.quoteRows ?? [],
        this.orderDates,
        this.slaHours,
        (id: string | null) => this.strategyNameFor(id),
    );
},
```

`currentPasses`, `quoteRows`, `orderDates` and `slaHours` all already exist on this component — `escalationResolution` and `splitDeals` are called with them a few lines above.

- [ ] **Step 2: Syntax-check the template before syncing**

`npx @vue/compiler-dom` has no CLI. Install the package in a scratch directory and call `compile()` from a small script on the `.html.twig` file. Do this *before* syncing — a template syntax error otherwise shows up as a blank page four minutes later.

- [ ] **Step 3: Add the table**

One row per strategy, columns: name, quotes (N), auto-execution, mean escalation resolution, agent discount vs baseline discount, cycle time, mean tokens.

Requirements, not polish:

- **Show N in its own column, always.** It is what stops the table being read as a result.
- **A null figure renders as `–`.** `formatSpan(null)` already returns `–`; match it for the non-duration figures rather than printing `0`.
- **An unnamed group** (`name === null`) renders under a snippet like "Unattributed", not a blank cell and not an invented name. Rows predating the `strategy_version_id` column land here, and that is information.
- **`mixedQuotes > 0` renders a footnote on that row** saying how many of its quotes changed strategy mid-negotiation.
- **No highlighting of a "best" row.** No bolding, no colour, no sort by score. Sort by name.
- Use `mt-badge` with the semantic variants only — `success` is not a valid variant and renders unstyled.

- [ ] **Step 4: Add the snippets**

Column headers, the "Unattributed" label, the mixed-strategy footnote, and a short explanatory line under the table stating that counts are small and no winner is implied.

- [ ] **Step 5: Sync, rebuild, screenshot**

```bash
./scripts/sync-to-shop.sh
docker exec merchant-quote-shop sh -c 'cd /var/www/html && bin/build-administration.sh'
```

Then screenshot `http://localhost:8095/admin` with `playwright-core` against the cached browser. The executable is at
`~/Library/Caches/ms-playwright/chromium-<n>/chrome-mac-arm64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing` — not `chrome-mac/Chromium.app`.

`fullPage: true` captures only the viewport because the admin scrolls an inner container. Use a tall viewport (3400px) plus `clip` regions.

Expected at this point: the table renders with a single "Unattributed" row, because no local decision row carries a `strategy_version_id` yet. That is the correct output for this data, and Task 5 gives it something to group.

- [ ] **Step 6: Commit**

```bash
git add src/Resources/app/administration/src/module/merchant-quote-agent/
git commit -m "feat(admin): compare the success measures across strategies

One row per strategy with its N beside every figure, no winner declared,
and no highlighting — B2B quote volume does not reach significance and
#99 is explicit that dressing 6 vs 4 up as a result is the failure mode.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 5: Seed decision rows so the table has something to show

**Files:**
- Create: `scripts/seed-decisions.php`

**Interfaces:**
- Consumes: nothing.
- Produces: nothing downstream. Dev tooling only; no test depends on it.

**Measured starting point, 2026-09-17.** `merchant-quote-shop` holds 9 decision rows over 4 quotes, 7 with token counts, and **0 with a `strategy_version_id`**. The three built-in strategies and their 3 versions exist. So the grouping has nothing to group until this task runs.

- [ ] **Step 1: Read the precedent**

Read `scripts/seed-order-history.php` and follow its shape — argument handling, how it reaches the database, how it reports what it wrote.

- [ ] **Step 2: Check what the current enums actually emit**

```bash
docker exec -w /var/www/html merchant-quote-shop mysql -uroot -proot -h127.0.0.1 -N -B shopware -e \
  "SELECT outcome, COUNT(*) FROM merchant_quote_agent_decision GROUP BY outcome"
```

You will see `replied` among them. **No `NegotiationOutcome` case emits that** — the cases are `offered`, `countered`, `escalated`, `nothing_to_do`, `clarified`. It is fixture noise from an older shape, and the admin module has shipped blank cells twice from exactly this kind of drift.

Seed only values the current enums emit. Leave the stale rows alone — they are a useful test of the "never assume a row is well-formed" constraint.

- [ ] **Step 3: Write the seeder**

Write rows across all three strategy versions with: varied outcomes, multi-pass quotes (so `rounds` and the token sum are exercised), at least one escalation-only quote (so the fallback in `attributeStrategy` is exercised on real data), at least one quote whose passes span two strategy versions (so `mixedQuotes` is non-zero), some rows with null token counts, and terminal states including `accepted`.

Make it idempotent or clearly re-runnable, and print what it wrote.

- [ ] **Step 4: Run it and confirm the grouping**

```bash
php scripts/seed-decisions.php
docker exec -w /var/www/html merchant-quote-shop mysql -uroot -proot -h127.0.0.1 -N -B shopware -e \
  "SELECT LOWER(HEX(strategy_version_id)), COUNT(*) FROM merchant_quote_agent_decision GROUP BY strategy_version_id"
```

Expected: three non-null groups plus the pre-existing null group.

- [ ] **Step 5: Re-screenshot and check the table against the numbers**

Sync, rebuild, screenshot as in Task 4. Then verify each rendered figure against the seeded data by hand — at minimum the quote counts, the auto-execution rate, and the mean tokens. A table that renders is not a table that is right.

Send the screenshot to the user.

- [ ] **Step 6: Commit**

```bash
git add scripts/seed-decisions.php
git commit -m "test(admin): seed decision rows across the built-in strategies

The local shop had nine decision rows and not one carried a
strategy_version_id, so the grouped table had nothing to group. Seeds
multi-pass quotes, an escalation-only quote, a strategy-switching quote
and rows with no token counts, using only values the current enums emit.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Verification before calling this done

- [ ] `composer run quality:admin` passes, all three check scripts.
- [ ] `composer run quality:dupes` reports no new duplication.
- [ ] `composer run quality` passes in full.
- [ ] A screenshot shows the table with at least three named strategy rows, each with its N.
- [ ] At least three rendered figures have been checked by hand against the seeded rows.
- [ ] A strategy with no comparable baseline shows `–`, not `0`.
- [ ] Rows with no `strategy_version_id` appear under "Unattributed" rather than vanishing.

Deferred by the spec, and out of scope here: the confirming pass against hoelshare's real rows, which cannot happen until Track A has run.

---

### Task 6: Roll rows up to the strategy, not the version

**Files:**
- Modify: `src/Resources/app/administration/src/module/merchant-quote-agent/strategy-measures.ts`
- Modify: `src/Resources/app/administration/src/module/merchant-quote-agent/strategy-measures.check.mjs`
- Modify: `src/Resources/app/administration/src/module/merchant-quote-agent/page/merchant-quote-agent-list/index.ts`
- Modify: `.../merchant-quote-agent-list/merchant-quote-agent-list.html.twig`
- Modify: `.../snippet/en.json`, `.../snippet/de.json`

**Interfaces:**
- Consumes: `attributeStrategy`, `groupByQuoteId` (Task 2); `strategyNameFor` (Task 3), which this task **replaces**.
- Produces: `strategyRows(passes, quoteRows, orderDates, slaHours, strategyOf)` where the last argument changes from
  `nameFor: (strategyVersionId: string | null) => string | null`
  to
  `strategyOf: (strategyVersionId: string | null) => { strategyId: string; name: string | null; version: number } | null`.
  Each returned row gains `versions: number[]` (ascending, distinct) and its `strategyVersionId` field becomes `strategyId: string | null`.

**The defect this fixes.** `groupPassesByStrategy` keys its `Map` by `strategyVersionId`, but `strategyRows` labels each row with the *strategy* name. Every strategy currently has exactly one version, so this is invisible. `StrategyVersion`'s contract is that editing a strategy **appends** a version and never rewrites one — so the first prompt edit produces two rows both labelled "Margin defender", indistinguishable, each holding a fraction of the data. It ships the moment a merchant edits a strategy.

**Why roll up rather than split.** The question the table answers is "which strategy should I run?" A version is a prompt edit within one posture. Splitting divides an already-small N — #99 is explicit that B2B quote volume does not reach significance and that the table must not let 6-vs-4 read as a result. Version-level comparison is real, but it belongs where the volume supports it: the bench readout, which runs hundreds of negotiations. Version remains the **audit** key on every decision row; it stops being the **reporting** key.

**The semantic change to `mixedQuotes`.** It currently counts a quote whose passes span two strategy *versions*. After this change it must count a quote whose passes span two *strategies* — that is the contaminating case for a strategy comparison. A quote that ran v1 then v2 of the same strategy is not contamination, and flagging it would cry wolf. The version spread is surfaced separately, by `versions`.

- [ ] **Step 1: Write the failing assertions**

Extend `strategy-measures.check.mjs`. Add to the existing fixtures a second version of one strategy, and a quote that spans those two versions.

```js
// --------------------------------------------------- rollup across versions

// v1 and v2 are two versions of ONE strategy. Before this rollup they produced
// two rows both labelled "Margin defender"; now they are one row.
const versionOf = (id) => ({
    v1: { strategyId: 's-margin', name: 'Margin defender', version: 1 },
    v2: { strategyId: 's-margin', name: 'Margin defender', version: 2 },
    v9: { strategyId: 's-fast', name: 'Fast close', version: 1 },
})[id] ?? null;

const rollupPasses = [
    { id: 'r1', quoteId: 'q1', outcome: 'offered', strategyVersionId: 'v1', createdAt: iso(2), totalNetBefore: 1000, totalNetAfter: 900, promptTokens: 100, completionTokens: 0 },
    { id: 'r2', quoteId: 'q2', outcome: 'offered', strategyVersionId: 'v2', createdAt: iso(2), totalNetBefore: 1000, totalNetAfter: 950, promptTokens: 300, completionTokens: 0 },
    { id: 'r3', quoteId: 'q3', outcome: 'offered', strategyVersionId: 'v9', createdAt: iso(1), totalNetBefore: 500, totalNetAfter: 475, promptTokens: 200, completionTokens: 0 },
];

const rolled = strategyRows(rollupPasses, [], new Map(), null, versionOf);

// One row per STRATEGY, not per version.
assert.deepEqual(rolled.map((r) => r.name), ['Margin defender', 'Fast close']);
assert.equal(rolled.length, 2);

// Both versions' quotes land in the one row, so N is not fragmented.
assert.equal(rolled[0].quotes, 2);
// ...and the spread is visible rather than silent.
assert.deepEqual(rolled[0].versions, [1, 2]);
assert.deepEqual(rolled[1].versions, [1]);

// Tokens sum across versions: (100 + 300) / 2 quotes.
assert.equal(rolled[0].tokens.meanTokens, 200);

// A quote spanning two VERSIONS of one strategy is not contamination.
const spansVersions = strategyRows([
    { id: 'a', quoteId: 'q7', outcome: 'offered', strategyVersionId: 'v2', createdAt: iso(3), totalNetBefore: 1000, totalNetAfter: 900 },
    { id: 'b', quoteId: 'q7', outcome: 'offered', strategyVersionId: 'v1', createdAt: iso(2), totalNetBefore: 1000, totalNetAfter: 950 },
], [], new Map(), null, versionOf);
assert.equal(spansVersions.length, 1);
assert.equal(spansVersions[0].mixedQuotes, 0);
assert.deepEqual(spansVersions[0].versions, [1, 2]);

// A quote spanning two STRATEGIES is contamination, and still counted.
const spansStrategies = strategyRows([
    { id: 'c', quoteId: 'q8', outcome: 'offered', strategyVersionId: 'v9', createdAt: iso(3), totalNetBefore: 1000, totalNetAfter: 900 },
    { id: 'd', quoteId: 'q8', outcome: 'offered', strategyVersionId: 'v1', createdAt: iso(2), totalNetBefore: 1000, totalNetAfter: 950 },
], [], new Map(), null, versionOf);
assert.equal(spansStrategies[0].mixedQuotes, 1);

// An unresolvable version id still groups, under null, rather than vanishing.
assert.equal(strategyRows(rollupPasses, [], new Map(), null, () => null).length, 1);
```

Keep every existing assertion in the file passing; update only the `nameFor` call sites to the new `strategyOf` shape.

- [ ] **Step 2: Run to verify it fails**

```bash
node src/Resources/app/administration/src/module/merchant-quote-agent/strategy-measures.check.mjs
```

Expected: fails — rows are still keyed by version, so `rolled.length` is 3 and `versions` is undefined.

- [ ] **Step 3: Implement**

In `strategy-measures.ts`: keep `attributeStrategy` returning a **version** id — that is the correct audit-level attribution and its escalation-only fallback is already reviewed. Add a rollup step that maps each attributed version id through `strategyOf` to a strategy id, and key the group `Map` on that instead. Collect each group's distinct version numbers, ascending, into `versions`. Recompute `mixedQuotes` over strategy ids rather than version ids.

Update the file's docblock: it currently explains grouping in terms of versions.

- [ ] **Step 4: Run to verify it passes**

```bash
node src/Resources/app/administration/src/module/merchant-quote-agent/strategy-measures.check.mjs
```

- [ ] **Step 5: Replace the page resolver**

`strategyNameFor` becomes `strategyOf`, returning `{ strategyId, name, version }` from the already-loaded `strategyVersions` and `strategies` arrays. Both reads already exist; `version` is `StrategyVersion.version`, an int column. Keep the null-on-unknown behaviour and the try/catch-null ACL guard exactly as they are.

- [ ] **Step 6: Show the spread in the template**

Render the version range beside the strategy name — `v2` for a single version, `v1–v3` for a span. A row whose `versions` is empty (unattributed) shows nothing. Add snippet keys to **both** `en.json` and `de.json`, with real German.

- [ ] **Step 7: Verify against the shop**

```bash
./scripts/sync-to-shop.sh
docker exec merchant-quote-shop sh -c 'cd /var/www/html && bin/build-administration.sh'
```

Seed a second version of one strategy (extend `scripts/seed-decisions.php` or add rows directly) so the rollup is exercised on real data, then screenshot at full width and hand-check that the rolled-up row's N equals the sum of its versions' quotes.

- [ ] **Step 8: Commit**

```bash
git add src/Resources/app/administration/src/module/merchant-quote-agent/ scripts/
git commit -m "fix(admin): compare strategies, not strategy versions

Rows were keyed by strategy_version_id but labelled with the strategy
name, so the first prompt edit produced two rows both called "Margin
defender", each holding half the data. Versions are prompt edits within
one posture; splitting on them fragments an N that is already too small
to call a winner on. The spread is shown instead, and mixedQuotes now
counts quotes spanning two strategies rather than two versions.

Version stays the audit key on every decision row; it stops being the
reporting key.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```
