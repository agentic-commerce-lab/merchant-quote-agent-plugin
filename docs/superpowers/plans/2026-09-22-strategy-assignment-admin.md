# Strategy Assignment Admin Tab Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give merchants a UI to create the customer pins, rule bindings and split arms that the assignment ladder already reads, so A/B testing of negotiation strategies stops being API-only.

**Architecture:** A second tab on the existing Negotiation strategies page, holding three grids in ladder order. All non-trivial logic lives in one pure module, `assignment.ts`, checked by a `.check.mjs` self-test that pins the `kind` vocabulary against the PHP enum by regex. The grids are thin: repository CRUD plus rendering.

**Tech Stack:** Shopware 6.7 administration (Vue 3, Twig templates, `Shopware.Component.register`, `repositoryFactory`, Meteor `mt-*` components), TypeScript with no build-time test runner — self-checks run under `node --experimental-strip-types`.

**Spec:** `docs/superpowers/specs/2026-09-18-strategy-assignment-design.md` — the "Admin surfaces" section is what this plan implements. Read the whole spec; the ladder's semantics decide what the UI must and must not allow.

## Global Constraints

- **This plan adds no PHP, no entity and no migration.** The table, the entity and the ACL read privilege shipped in PR #183. If a task seems to need a schema change, stop and report — it means the plan or the spec is wrong.
- **The admin is now the only guard against a `kind = 'rule'` row with a NULL `rule_id`.** PR #185 removed `rule_id` from the table's CHECK constraint, because MySQL 8.0.16+ refuses (SQLSTATE 3823) any CHECK naming a column that carries a foreign key referential action, and `rule_id` cascades. Such a row is inert — the resolver skips it — but it is invisible misconfiguration, so the rules grid must never create or save one.
- **`kind` values are `pin`, `rule`, `split`.** Never `config`, which is the absence of a row. They are the backing values of `MerchantQuoteAgentPlugin\Strategy\StrategyAssignmentSource`.
- **Admin constants that mirror PHP must be pinned against the PHP file itself**, by regex, in a `.check.mjs` — never against a second literal copy declared in the check file. `strategy.check.mjs` does this for `BUILT_IN_IDS`; read it before writing yours. Admin vocabulary drifting from a backend enum has shipped twice in this project.
- **A new `.check.mjs` is not run until it is added to `composer.json`'s `quality:admin` script**, which is an explicit `&&` chain of filenames, not a glob. Forgetting this means the self-check never runs and the gate stays green.
- **`mt-badge` has no `success` variant** — it renders unstyled. Use the semantic tokens the codebase already uses; grep the existing templates for `mt-badge` before adding one.
- Snippets go in both `snippet/en.json` and `snippet/de.json`, under the existing `merchant-quote-agent` root. Every user-visible string is a snippet; none are inline.
- Quality gate before every commit: `composer format && composer lint && composer typecheck && composer quality:admin`. The PHP unit suite must stay green (`composer test`, 1313 tests at the time of writing).
- **Admin type-check baseline:** `.shopware-admin-baseline.json` is generated inside a shop container. Do not hand-edit it. If `composer quality:admin:shop` is unavailable to you, say so in your report rather than inventing baseline entries.
- Every commit message ends with `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.
- Commits are signed. If `git commit` fails with `1Password: agent returned an error`, prefix with `export SSH_AUTH_SOCK="$HOME/Library/Group Containers/2BUA8C4S2C.com.1password/t/agent.sock"` and retry twice; then report BLOCKED with work staged rather than bypassing signing.

---

## File Structure

**Created:**

| Path | Responsibility |
| --- | --- |
| `…/merchant-quote-agent/assignment.ts` | Every non-trivial decision this feature makes: the `kind` vocabulary, the weight-to-percentage maths, and the validity predicate each grid saves through. Pure — no Vue, no repository. |
| `…/merchant-quote-agent/assignment.check.mjs` | Self-check for the above, pinning the vocabulary against the PHP enum. |

**A note on the template, learned in Task 2.** An earlier draft of this plan
put the tab's markup in its own `assignments.html.twig`. That does not work: a
Shopware administration component has exactly one template file, and there is no
cross-file include for an arbitrary non-component twig. Everything lives in
`merchant-quote-agent-strategies.html.twig`, inside the
`{% block merchant_quote_agent_assignments %}` Task 2 created, and Tasks 3-5
append sections to that block.

**Modified:** `…/page/merchant-quote-agent-strategies/index.ts` and `…-strategies.html.twig` (tab shell + grid state), `…/acl/index.ts`, `…/snippet/en.json`, `…/snippet/de.json`, `…/merchant-quote-agent.scss`, `…/strategy-measures.ts` and the list page's template (assignment spread), `composer.json` (`quality:admin`).

`…` is `src/Resources/app/administration/src/module/merchant-quote-agent`.

---

### Task 1: The pure module and its self-check

**Files:**
- Create: `…/assignment.ts`
- Create: `…/assignment.check.mjs`
- Modify: `composer.json` (the `quality:admin` script)

**Interfaces:**
- Consumes: nothing.
- Produces: `ASSIGNMENT_KINDS` (`readonly ['pin','rule','split']`); `ASSIGNMENT_SOURCES` (`readonly ['pin','rule','split','config']`); `interface AssignmentLike { kind: string; customerId: string | null; ruleId: string | null; weight: number | null; strategyId: string | null; salesChannelId: string | null }`; `isSavable(row: AssignmentLike): boolean`; `splitShares<T extends {weight: number | null}>(arms: T[]): { arm: T; percent: number }[]`; `spreadLabel(sources: (string|null)[]): {source: string; count: number}[]`.

- [ ] **Step 1: Write the failing self-check**

Create `…/assignment.check.mjs`. Model its header comment and its `extractConstant`-by-regex technique on `…/strategy.check.mjs` — read that file first and follow it rather than inventing a second style.

```js
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { ASSIGNMENT_KINDS, ASSIGNMENT_SOURCES, isSavable, splitShares, spreadLabel } from './assignment.ts';

/**
 * The vocabulary is read out of the PHP enum, not out of a copy declared here.
 * A copy would only prove assignment.ts agrees with this file. Admin
 * vocabulary drifting from a backend enum has shipped twice in this project.
 */
function phpEnumValues() {
    const source = readFileSync(
        new URL('../../../../../../Strategy/StrategyAssignmentSource.php', import.meta.url),
        'utf8',
    );

    return [...source.matchAll(/case\s+\w+\s*=\s*'([a-z]+)'\s*;/g)].map((m) => m[1]);
}

const php = phpEnumValues();
assert.ok(php.length === 4, `expected 4 enum cases in the PHP source, found ${php.length}`);
assert.deepEqual([...ASSIGNMENT_SOURCES], php, 'ASSIGNMENT_SOURCES must equal the PHP enum, in order');
assert.deepEqual([...ASSIGNMENT_KINDS], php.filter((v) => v !== 'config'),
    'ASSIGNMENT_KINDS is every source except config, which is the absence of a row');

// isSavable: the admin is the ONLY guard against a rule row with no rule id,
// because PR #185 had to drop rule_id from the table's CHECK constraint.
assert.equal(isSavable({ kind: 'rule', customerId: null, ruleId: null, weight: null, strategyId: 'a', salesChannelId: null }), false);
assert.equal(isSavable({ kind: 'rule', customerId: null, ruleId: 'r', weight: null, strategyId: 'a', salesChannelId: null }), true);
assert.equal(isSavable({ kind: 'pin', customerId: null, ruleId: null, weight: null, strategyId: 'a', salesChannelId: null }), false);
assert.equal(isSavable({ kind: 'pin', customerId: 'c', ruleId: null, weight: null, strategyId: 'a', salesChannelId: null }), true);
assert.equal(isSavable({ kind: 'split', customerId: null, ruleId: null, weight: null, strategyId: 'a', salesChannelId: null }), false);
assert.equal(isSavable({ kind: 'split', customerId: null, ruleId: null, weight: 1, strategyId: 'a', salesChannelId: null }), true);
assert.equal(isSavable({ kind: 'split', customerId: null, ruleId: null, weight: 0, strategyId: 'a', salesChannelId: null }), false,
    'a zero-weight arm can never be chosen, so saving one is a silent no-op');
assert.equal(isSavable({ kind: 'split', customerId: null, ruleId: null, weight: -1, strategyId: 'a', salesChannelId: null }), false,
    'a negative weight drives the arm total toward zero and can lose the assignment entirely');
assert.equal(isSavable({ kind: 'split', customerId: null, ruleId: null, weight: 1, strategyId: null, salesChannelId: null }), false,
    'every row must name a strategy');

// splitShares: percent of the arms' OWN sum, so 1 and 4 read as 20% and 80%.
assert.deepEqual(splitShares([{ weight: 1 }, { weight: 4 }]).map((s) => s.percent), [20, 80]);
assert.deepEqual(splitShares([{ weight: 20 }, { weight: 80 }]).map((s) => s.percent), [20, 80]);
assert.deepEqual(splitShares([{ weight: 1 }, { weight: 1 }, { weight: 1 }]).map((s) => s.percent), [33.3, 33.3, 33.3],
    'thirds are rounded for display and deliberately do not total 100 -- the resolver uses the raw weights');
assert.deepEqual(splitShares([]).map((s) => s.percent), []);
assert.deepEqual(splitShares([{ weight: 0 }, { weight: 0 }]).map((s) => s.percent), [0, 0],
    'a zero total must not divide by zero');
assert.deepEqual(splitShares([{ weight: null }, { weight: 4 }]).map((s) => s.percent), [0, 100]);

// spreadLabel: how a decision row's assignment sources are summarised.
assert.deepEqual(spreadLabel(['rule', 'rule', 'config']), [{ source: 'rule', count: 2 }, { source: 'config', count: 1 }]);
assert.deepEqual(spreadLabel([]), []);
assert.deepEqual(spreadLabel([null, null]), [], 'rows written before the column existed are not a source');
assert.deepEqual(spreadLabel(['config', 'pin', 'config']), [{ source: 'config', count: 2 }, { source: 'pin', count: 1 }],
    'ordered by count descending so the dominant source reads first');

console.log('assignment.check.mjs OK');
```

Check the relative path in `phpEnumValues()` against the real tree before trusting it: from `src/Resources/app/administration/src/module/merchant-quote-agent/` to `src/Strategy/` is six levels up. Count them; if it differs, fix the URL, not the test.

- [ ] **Step 2: Run it to verify it fails**

Run: `node --experimental-strip-types src/Resources/app/administration/src/module/merchant-quote-agent/assignment.check.mjs`
Expected: FAIL — cannot resolve `./assignment.ts`.

- [ ] **Step 3: Write the module**

Create `…/assignment.ts`:

```ts
/**
 * Pure helpers for the strategy assignment tab. No Vue, no repository: the
 * grids are thin, and everything here is checked by assignment.check.mjs.
 *
 * The vocabulary mirrors `MerchantQuoteAgentPlugin\Strategy\
 * StrategyAssignmentSource`. It is duplicated rather than fetched because four
 * frozen words are not worth a network round trip, and assignment.check.mjs
 * pins the duplication against the PHP enum itself.
 */

export const ASSIGNMENT_SOURCES = ['pin', 'rule', 'split', 'config'] as const;

/** Every source except `config`, which is the absence of a row, never a row. */
export const ASSIGNMENT_KINDS = ['pin', 'rule', 'split'] as const;

export interface AssignmentLike {
    kind: string;
    customerId: string | null;
    ruleId: string | null;
    weight: number | null;
    strategyId: string | null;
    salesChannelId: string | null;
}

/**
 * Whether this row is worth writing.
 *
 * This is the only guard against a `kind = 'rule'` row with no `rule_id`. The
 * table's CHECK constraint cannot forbid it: MySQL 8.0.16+ rejects any CHECK
 * naming a column that carries a foreign key referential action, and `rule_id`
 * cascades so that deleting a rule in core's rule builder does not error. Such
 * a row is inert -- the resolver skips it -- which is exactly why it must not
 * be creatable here: inert misconfiguration is invisible misconfiguration.
 *
 * A zero or negative weight is refused for the same reason. Zero can never win
 * a bucket, and a negative one reduces the arms' total, which can drive it to
 * zero and lose the assignment for every customer in that channel.
 */
export function isSavable(row: AssignmentLike): boolean {
    if (typeof row.strategyId !== 'string' || row.strategyId === '') {
        return false;
    }

    if (row.kind === 'pin') {
        return typeof row.customerId === 'string' && row.customerId !== '';
    }

    if (row.kind === 'rule') {
        return typeof row.ruleId === 'string' && row.ruleId !== '';
    }

    if (row.kind === 'split') {
        return typeof row.weight === 'number' && row.weight > 0;
    }

    return false;
}

/**
 * Each arm's share of the arms' OWN sum, so a merchant typing 1 and 4 sees 20%
 * and 80% instead of a validation error demanding they total 100. Rounded to
 * one decimal for display only -- the resolver buckets on the raw integers, so
 * three equal arms showing 33.3% each is correct, not a rounding bug to fix.
 */
export function splitShares<T extends { weight: number | null }>(arms: T[]): { arm: T; percent: number }[] {
    const total = arms.reduce((sum, arm) => sum + Math.max(arm.weight ?? 0, 0), 0);

    return arms.map((arm) => ({
        arm,
        percent: total === 0 ? 0 : Math.round((Math.max(arm.weight ?? 0, 0) / total) * 1000) / 10,
    }));
}

/**
 * The assignment sources behind a set of passes, commonest first.
 *
 * This is what makes a silent fall-through legible: the rule rung skips itself
 * with only a log warning when a quote's customer has no active shipping
 * address, so a strategy row reading `rule: 12, config: 40` means the rule
 * matched far less often than the merchant believes. A null source is a row
 * written before the column existed and is not counted.
 */
export function spreadLabel(sources: (string | null)[]): { source: string; count: number }[] {
    const counts = new Map<string, number>();

    for (const source of sources) {
        if (typeof source === 'string' && source !== '') {
            counts.set(source, (counts.get(source) ?? 0) + 1);
        }
    }

    return [...counts.entries()]
        .map(([source, count]) => ({ source, count }))
        .sort((a, b) => b.count - a.count || a.source.localeCompare(b.source));
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `node --experimental-strip-types src/Resources/app/administration/src/module/merchant-quote-agent/assignment.check.mjs`
Expected: `assignment.check.mjs OK`.

- [ ] **Step 5: Wire it into the gate**

In `composer.json`, append to the `quality:admin` script's `&&` chain, after the `strategy-measures.check.mjs` entry:

```
 && node --experimental-strip-types src/Resources/app/administration/src/module/merchant-quote-agent/assignment.check.mjs
```

The chain is an explicit list of filenames, not a glob. Verify with `composer quality:admin` that five checks now run, and that yours is one of them — read the output, do not assume.

- [ ] **Step 6: Prove the vocabulary pin actually fires**

Temporarily add a fifth case to `src/Strategy/StrategyAssignmentSource.php` (`case Bogus = 'bogus';`), run `composer quality:admin`, confirm it fails on the `expected 4 enum cases` assertion, then revert the PHP file and confirm `git diff` on it is empty. Record both outputs in your report. This is the one assertion the whole anti-drift argument rests on; if it cannot fail, the rest is decoration.

- [ ] **Step 7: Quality gate and commit**

```bash
composer format && composer lint && composer typecheck && composer quality:admin && composer test
git add src/Resources/app/administration/src/module/merchant-quote-agent/assignment.ts src/Resources/app/administration/src/module/merchant-quote-agent/assignment.check.mjs composer.json
git commit -m "feat(admin): add the assignment tab's pure helpers

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 2: ACL, and the tab shell

**Files:**
- Modify: `…/acl/index.ts`
- Modify: `…/page/merchant-quote-agent-strategies/merchant-quote-agent-strategies.html.twig`
- Modify: `…/page/merchant-quote-agent-strategies/index.ts`
- Modify: `…/snippet/en.json`, `…/snippet/de.json`

**Interfaces:**
- Consumes: nothing from Task 1 yet.
- Produces: an `activeTab` data property on the strategies page (`'library' | 'assignments'`); an `assignmentRepository` computed returning `repositoryFactory.create('merchant_quote_agent_strategy_assignment')`; an `assignments` data array loaded on mount; a `canEdit` computed that already exists. The three grids in Tasks 3-5 append sections inside the `{% block merchant_quote_agent_assignments %}` this task creates in the page's own template.

- [ ] **Step 1: Extend the ACL**

In `…/acl/index.ts`, the `viewer` role already has `merchant_quote_agent_strategy_assignment:read` from PR #183. Add `rule:read` beside it, with a comment saying the rules grid reads rule names and priorities for display and 403s without it, the same way the existing `quote:read` and `order:read` comments explain themselves.

Then extend the `editor` role with:

```ts
                'merchant_quote_agent_strategy_assignment:create',
                'merchant_quote_agent_strategy_assignment:update',
                'merchant_quote_agent_strategy_assignment:delete',
```

Note the `delete` — and extend the comment above `editor`, which currently explains why strategies have no delete. Assignments genuinely are deleted: unlike a strategy, an assignment row is not referenced by any decision record, so removing a pin or an arm destroys no history. Say that, so the next reader does not "fix" the inconsistency.

- [ ] **Step 2: Add the tab shell**

**There is no tab precedent in this module** — nothing under `…/merchant-quote-agent/` uses `sw-tabs` or `mt-tabs` today, so there is no local shape to copy and you are establishing one.

Use core's `sw-tabs`. Verify the component and its prop names against the installed core before trusting this snippet — find it under `vendor/shopware/administration/` (or the built admin bundle) and check whether this build's version wants `default-item` or a `v-model`, and whether the slot is named `content`:

```twig
        <sw-tabs default-item="library" position-identifier="merchant-quote-agent-strategies-tabs">
            <template #default="{ active }">
                <sw-tabs-item name="library" :active-tab="active">
                    {{ $tc('merchant-quote-agent.assignment.tabLibrary') }}
                </sw-tabs-item>
                <sw-tabs-item name="assignments" :active-tab="active">
                    {{ $tc('merchant-quote-agent.assignment.tabAssignments') }}
                </sw-tabs-item>
            </template>

            <template #content="{ active }">
                <template v-if="active === 'library'">{# existing library blocks, unchanged #}</template>
                <template v-else>{# the merchant_quote_agent_assignments block, below #}</template>
            </template>
        </sw-tabs>
```

The existing library UI keeps its current `{% block %}` names and its markup untouched inside the first tab, so template overrides that target those blocks keep working — moving a block is a breaking change for anyone extending this page.

If `sw-tabs` turns out to be unavailable or deprecated in this build, **do not invent a replacement**: fall back to rendering the assignments as a second `mt-card` stacked below the library card on the same page, which needs no new idiom and is what the page already does. Report which you used and why. The spec asks for a tab; a working card beats a broken tab, and the choice is visible either way.

Add a `{% block merchant_quote_agent_assignments %}` to the page's own template, holding just a card and an empty state for now. It goes in this file, not a separate one: an administration component has exactly one template, and there is no cross-file include for an arbitrary non-component twig.

```twig
{% block merchant_quote_agent_assignments %}
    <mt-card :title="$tc('merchant-quote-agent.assignment.cardTitle')" position-identifier="merchant-quote-agent-assignments">
        {% block merchant_quote_agent_assignments_intro %}
            <p class="mqa-assignment__intro">{{ $tc('merchant-quote-agent.assignment.intro') }}</p>
        {% endblock %}
    </mt-card>
{% endblock %}
```

- [ ] **Step 3: Load the rows**

In `…/page/merchant-quote-agent-strategies/index.ts`, add to `data()`: `activeTab: 'library'`, `assignments: []`. Add a computed `assignmentRepository()` returning `this.repositoryFactory.create('merchant_quote_agent_strategy_assignment')`, mirroring the existing `repository()` and `versionRepository()` computeds exactly.

Add a `loadAssignments()` method that searches that repository with a `Criteria` and assigns the result, following how the page's existing load method builds its criteria. Call it from the same lifecycle hook the existing load uses.

- [ ] **Step 4: Add the snippets**

In both `snippet/en.json` and `snippet/de.json`, add an `assignment` key under the `merchant-quote-agent` root, beside the existing `strategy` key. For this task it needs `tabLibrary`, `tabAssignments`, `cardTitle` and `intro`. The intro text should say, in one sentence, that these rows decide which strategy a quote gets and that they are tried in order — pin, then rule, then split — before the sales-channel default applies.

Write real German in `de.json`, not English placeholders. Match the register of the existing German strings.

- [ ] **Step 5: See it render**

Run `composer format:check && composer lint && composer typecheck && composer quality:admin`. These do not render the page, so also state plainly in your report that you have not seen the tab in a browser and that the first person with a shop should check it.

If you have a shop available and know the bundle-rebuild path, use it and say so; do not spend more than a few minutes trying to obtain one.

- [ ] **Step 6: Quality gate and commit**

```bash
composer format && composer lint && composer typecheck && composer quality:admin && composer test
git add src/Resources/app/administration/src/module/merchant-quote-agent/
git commit -m "feat(admin): add the assignment tab and its privileges

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 3: The pinned-customers grid

**Files:**
- Modify: `…/page/merchant-quote-agent-strategies/merchant-quote-agent-strategies.html.twig`, inside the `{% block merchant_quote_agent_assignments %}` Task 2 created
- Modify: `…/page/merchant-quote-agent-strategies/index.ts`
- Modify: `…/snippet/en.json`, `…/snippet/de.json`
- Modify: `…/merchant-quote-agent.scss` (only if the grid needs a class the file does not already provide)

**Interfaces:**
- Consumes: `isSavable` and `ASSIGNMENT_KINDS` from Task 1; `assignmentRepository`, `assignments`, `canEdit` from Task 2.
- Produces: `addPin()`, plus two methods shared by all three grids and therefore written generically here rather than three times: `saveAssignment(row)` and `removeAssignment(row)`. Tasks 4 and 5 call those two by exactly those names; do not rename them to `savePin`/`saveRule`.

- [ ] **Step 1: Add the grid**

In the page template's `merchant_quote_agent_assignments` block, add a first section: a heading, a `sw-data-grid` (or the Meteor equivalent the codebase already uses — grep the strategies page's existing grid and copy its component and prop shape) bound to a `pins` computed, with columns for customer, strategy, sales channel, and a delete action.

Cells:
- **Customer** — `sw-entity-single-select` on `customer`, bound to `row.customerId`. Add the snippet help text explaining that on a B2B shop this is the company account, so the pin covers every employee and organization unit of it. That is not a UI nicety: `QuoteIdentity`'s own docblock says the id is the company, and a merchant expecting per-person targeting would be wrong.
- **Strategy** — reuse the existing `merchant-quote-agent-strategy-select` component rather than a second selector.
- **Sales channel** — `sw-entity-single-select` on `sales_channel`, nullable, with a snippet placeholder saying that empty means every channel.

- [ ] **Step 2: Add the methods**

In `index.ts`:

```ts
        addPin() {
            const row = this.assignmentRepository.create(Shopware.Context.api);
            row.kind = 'pin';
            row.customerId = null;
            row.ruleId = null;
            row.weight = null;
            row.strategyId = null;
            row.salesChannelId = null;
            this.assignments.push(row);
        },
```

and a save that refuses an incomplete row rather than writing it:

```ts
        async saveAssignment(row) {
            if (!isSavable(row)) {
                this.error = this.$tc('merchant-quote-agent.assignment.incomplete');

                return;
            }

            this.error = null;
            await this.assignmentRepository.save(row, Shopware.Context.api);
            await this.loadAssignments();
        },

        async removeAssignment(row) {
            if (row.id !== undefined && row._isNew !== true) {
                await this.assignmentRepository.delete(row.id, Shopware.Context.api);
            }

            await this.loadAssignments();
        },
```

Check `_isNew` against how this administration build actually marks unsaved entities before relying on it; if the idiom differs here, follow the local one and say so in your report.

Add a `pins` computed returning `this.assignments.filter((row) => row.kind === 'pin')`.

- [ ] **Step 3: Extend the self-check**

Add to `assignment.check.mjs`, before the final `console.log`:

```js
// A pin row straight from addPin() must not be savable until it names both a
// customer and a strategy -- the grid's Save button is bound to this.
assert.equal(isSavable({ kind: 'pin', customerId: null, ruleId: null, weight: null, strategyId: null, salesChannelId: null }), false);
assert.equal(isSavable({ kind: 'pin', customerId: 'c', ruleId: null, weight: null, strategyId: null, salesChannelId: null }), false);
assert.equal(isSavable({ kind: 'pin', customerId: 'c', ruleId: null, weight: null, strategyId: 's', salesChannelId: null }), true);
assert.equal(isSavable({ kind: 'pin', customerId: 'c', ruleId: null, weight: null, strategyId: 's', salesChannelId: 'ch' }), true,
    'a channel-scoped pin is as valid as a global one');
```

- [ ] **Step 4: Run the checks**

Run: `composer quality:admin`
Expected: PASS, five checks, `assignment.check.mjs OK` among them.

- [ ] **Step 5: Add the snippets**

Both locales: `pinsTitle`, `pinsIntro`, `columnCustomer`, `columnStrategy`, `columnSalesChannel`, `customerIsCompany`, `anyChannel`, `addPin`, `incomplete`, `remove`. Real German throughout.

- [ ] **Step 6: Quality gate and commit**

```bash
composer format && composer lint && composer typecheck && composer quality:admin && composer test
git add src/Resources/app/administration/src/module/merchant-quote-agent/
git commit -m "feat(admin): pin a customer to a strategy

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 4: The rules grid

**Files:**
- Modify: `…/page/merchant-quote-agent-strategies/merchant-quote-agent-strategies.html.twig`, inside the `{% block merchant_quote_agent_assignments %}` Task 2 created
- Modify: `…/page/merchant-quote-agent-strategies/index.ts`
- Modify: `…/assignment.check.mjs`
- Modify: `…/snippet/en.json`, `…/snippet/de.json`

**Interfaces:**
- Consumes: `isSavable` from Task 1; `saveAssignment`, `removeAssignment`, `assignments`, `canEdit` from Tasks 2-3.
- Produces: a `rules` computed (`assignments.filter(row => row.kind === 'rule')`), a `ruleNames` map from rule id to `{ name, priority }`, and `addRule()`.

This grid is the one with real hazards. Read the spec's "What we verified before designing" section before writing it.

- [ ] **Step 1: Add the grid**

A second section in that same block: rule, strategy, sales channel, a **read-only priority** column, and delete.

- **Rule** — `sw-entity-single-select` on `rule`, bound to `row.ruleId`.
- **Priority** — read-only, sourced from the selected rule, not editable here. It is core's `rule.priority` and it is what orders the rung; showing it saves the merchant a trip to the rule builder to understand why one rule won.
- Sort the displayed rows by that priority, descending, so the grid reads in the order the resolver evaluates. A merchant who cannot see the evaluation order cannot reason about overlapping rules.

- [ ] **Step 2: Load the rule metadata**

The assignment entity stores `ruleId` as a plain UUID with no DAL association, so the names and priorities need a second read. Add a `ruleNames` data object and populate it in `loadAssignments()`: collect the non-null `ruleId`s, and if there are any, search `rule` with a `Criteria` over those ids, storing `{ name, priority }` per id. Skip the second read entirely when there are no rule rows.

- [ ] **Step 3: Add the flow-condition warning**

Below the grid, a snippet-driven note stating that conditions which only apply to orders or flows never match here, so a rule built from them will never be selected. Render it as a plain hint, not as an error.

Do not try to filter those conditions out of the rule select. That would mean mirroring a core list that changes between versions, to hide conditions a sentence can warn about — the spec rejects it explicitly under "Considered and rejected".

If you render this as a badge rather than text, note that `mt-badge` has no `success` variant and renders unstyled if given one. Grep the existing templates for `mt-badge` and reuse a variant already in use.

- [ ] **Step 4: Extend the self-check**

```js
// The admin is the only guard left against a rule row with no rule id: PR #185
// had to drop rule_id from the table's CHECK, because MySQL 8.0.16+ rejects a
// CHECK naming a column with a foreign key referential action. Such a row is
// inert -- the resolver skips it -- which is exactly why it must be unsavable.
assert.equal(isSavable({ kind: 'rule', customerId: null, ruleId: null, weight: null, strategyId: 's', salesChannelId: null }), false);
assert.equal(isSavable({ kind: 'rule', customerId: null, ruleId: '', weight: null, strategyId: 's', salesChannelId: null }), false);
assert.equal(isSavable({ kind: 'rule', customerId: null, ruleId: 'r', weight: null, strategyId: null, salesChannelId: null }), false);
assert.equal(isSavable({ kind: 'rule', customerId: null, ruleId: 'r', weight: null, strategyId: 's', salesChannelId: null }), true);
```

- [ ] **Step 5: Run the checks**

Run: `composer quality:admin`
Expected: PASS.

Then prove the guard discriminates: temporarily change `isSavable`'s `rule` branch to `return true;`, run `composer quality:admin`, confirm it fails on the first of the four assertions above, revert, and confirm `git diff` on `assignment.ts` is empty. Record the red output — this guard is the only thing standing between a merchant and an invisible dead rule binding.

- [ ] **Step 6: Add the snippets**

Both locales: `rulesTitle`, `rulesIntro`, `columnRule`, `columnPriority`, `flowConditionsNote`, `addRule`, `noRuleSelected`. The `flowConditionsNote` text must name the consequence — the rule never matches — not just the fact.

- [ ] **Step 7: Quality gate and commit**

```bash
composer format && composer lint && composer typecheck && composer quality:admin && composer test
git add src/Resources/app/administration/src/module/merchant-quote-agent/
git commit -m "feat(admin): bind a rule to a strategy

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 5: The split grid

**Files:**
- Modify: `…/page/merchant-quote-agent-strategies/merchant-quote-agent-strategies.html.twig`, inside the `{% block merchant_quote_agent_assignments %}` Task 2 created
- Modify: `…/page/merchant-quote-agent-strategies/index.ts`
- Modify: `…/snippet/en.json`, `…/snippet/de.json`

**Interfaces:**
- Consumes: `splitShares` and `isSavable` from Task 1; `saveAssignment`, `removeAssignment` from Task 3.
- Produces: an `arms` computed returning `splitShares(assignments.filter(row => row.kind === 'split'))`, and `addArm()`.

- [ ] **Step 1: Add the grid**

A third section: strategy, weight (`mt-number-field` or the codebase's existing numeric input — grep and match), the computed percentage as read-only text beside it, sales channel, and delete.

Group by sales channel if more than one is in use, because weights are only meaningful relative to the other arms in the same channel. If that turns out to complicate the template more than it helps, a single flat grid with a visible channel column is acceptable — say which you chose and why in your report.

- [ ] **Step 2: Wire the percentages**

The percentage column reads from the `arms` computed, never from a second calculation in the template. `splitShares` is already checked; a duplicate expression in Twig would not be.

Add a line under the grid explaining that weights are relative and need not total 100 — a merchant typing 1 and 4 gets 20% and 80%.

- [ ] **Step 3: Add the stickiness note**

A snippet under the grid stating that a customer keeps the same arm across all their quotes, and that changing any weight reshuffles which customers are in which arm.

Both halves matter and neither is guessable from the UI. The first is why the same buyer never sees two postures; the second is a real consequence of a stateless split that a merchant tuning weights mid-experiment needs to know before they do it.

- [ ] **Step 4: Run the checks**

Run: `composer quality:admin`
Expected: PASS. `splitShares` is already covered by Task 1's assertions, including the zero-total and null-weight cases; add no duplicates here.

- [ ] **Step 5: Add the snippets**

Both locales: `splitTitle`, `splitIntro`, `columnWeight`, `columnShare`, `weightsAreRelative`, `stickyPerCustomer`, `addArm`.

- [ ] **Step 6: Quality gate and commit**

```bash
composer format && composer lint && composer typecheck && composer quality:admin && composer test
git add src/Resources/app/administration/src/module/merchant-quote-agent/
git commit -m "feat(admin): split traffic between strategies by weight

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 6: The assignment spread on the measures table

**Files:**
- Modify: `…/strategy-measures.ts`
- Modify: `…/strategy-measures.check.mjs`
- Modify: `…/page/merchant-quote-agent-list/merchant-quote-agent-list.html.twig`
- Modify: `…/snippet/en.json`, `…/snippet/de.json`

**Interfaces:**
- Consumes: `spreadLabel` from Task 1.
- Produces: an `assignment` field on each row `strategyRows()` returns, shaped `{ source: string; count: number }[]`.

This is the task that makes the whole feature legible. Read `strategy-measures.ts`'s class docblock first: it already collapses strategy versions into a `versions` spread and reports what it collapsed rather than discarding it. Yours is the same move for assignment sources, and must render the same way.

- [ ] **Step 1: Extend the self-check**

In `strategy-measures.check.mjs`, append a new section in the file's existing style. Its fixtures follow the shape already used there — passes are flat objects and `strategyRows` takes five arguments, `(passes, quoteRows, orderDates, null, strategyOf)`. Re-read the section around its existing `const rows = strategyRows(passes, quoteRows, orderDates, null, strategyOf);` call and mirror it:

```js
// ------------------------------------------------- assignment spread
//
// One strategy reached three times: twice by the rule the merchant wrote and
// once by the sales-channel config key. That mix is the whole reason the
// column exists -- the rule rung skips itself silently when a quote's customer
// has no active shipping address, so `rule: 2, config: 1` is the only place a
// merchant sees it happened.
const spreadPasses = [
    { id: 'a1', quoteId: 'q1', outcome: 'offered', strategyVersionId: 'v1', createdAt: iso(3), strategyAssignmentSource: 'rule' },
    { id: 'a2', quoteId: 'q2', outcome: 'offered', strategyVersionId: 'v1', createdAt: iso(2), strategyAssignmentSource: 'rule' },
    { id: 'a3', quoteId: 'q3', outcome: 'offered', strategyVersionId: 'v1', createdAt: iso(1), strategyAssignmentSource: 'config' },
];
const spreadStrategyOf = (id) => (id === 'v1' ? { strategyId: 's-margin', name: 'Margin defender', version: 1 } : null);
const spreadRows = strategyRows(spreadPasses, new Map(), new Map(), null, spreadStrategyOf);

assert.deepEqual(spreadRows[0].assignment, [
    { source: 'rule', count: 2 },
    { source: 'config', count: 1 },
], 'commonest source first');

// A pass written before the column existed carries null and is not a source.
const legacyPasses = [
    { id: 'a4', quoteId: 'q4', outcome: 'offered', strategyVersionId: 'v1', createdAt: iso(1), strategyAssignmentSource: null },
];
assert.deepEqual(strategyRows(legacyPasses, new Map(), new Map(), null, spreadStrategyOf)[0].assignment, []);
```

Check the `quoteRows` and `orderDates` arguments against how the file's other cases build them — if empty `Map`s make an unrelated measure throw, copy the minimal shapes those cases use instead. Expectations are written out by hand on purpose: an assertion that calls `spreadLabel` to compute what it then compares against would prove nothing.

- [ ] **Step 2: Run it to verify it fails**

Run: `composer quality:admin`
Expected: FAIL — `rows[0].assignment` is `undefined`.

- [ ] **Step 3: Add the field**

In `strategy-measures.ts`, inside the object `strategyRows()` builds per row — beside where it already computes `versions` — add:

```ts
        assignment: spreadLabel(ownPasses.map((pass) => pass.strategyAssignmentSource ?? null)),
```

Use whatever the surrounding code actually calls the per-row pass collection; `ownPasses` is illustrative. Import `spreadLabel` from `./assignment.ts`.

Extend the file's class docblock with a sentence: the assignment spread is reported for the same reason as the version spread — it is collapsed information that would otherwise be discarded silently, and here it is the only place a merchant can see the rule rung falling through.

- [ ] **Step 4: Run it to verify it passes**

Run: `composer quality:admin`
Expected: PASS.

- [ ] **Step 5: Render it**

In the list page template, beside the existing `versions` spread rendering (around line 204-213), add the assignment spread in the same `mqa-cell__aside` idiom. Copy that block's structure; do not introduce a different one.

Render each entry as `source: count`, using a snippet for the source word so `pin`, `rule`, `split` and `config` are translated rather than shown as raw enum values.

- [ ] **Step 6: Add the snippets**

Both locales, under the existing `strategyComparison` key: `assignmentSpread` plus one label per source — `sourcePin`, `sourceRule`, `sourceSplit`, `sourceConfig`. German too.

- [ ] **Step 7: Quality gate and commit**

```bash
composer format && composer lint && composer typecheck && composer quality:admin && composer test
git add src/Resources/app/administration/src/module/merchant-quote-agent/
git commit -m "feat(admin): show which rung chose each strategy

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## What this plan does not do

**No browser verification.** Nothing here runs the administration. Every task's checks are static analysis plus the `.check.mjs` self-tests, which cover the logic and none of the rendering. The first person with a shop should walk all three grids and the measures column; a plan that claimed otherwise would be lying.

**No integration test.** `tests/Integration/QuoteRuleScopeTest.php` from PR #183 still has never executed, and it, not this UI, is what would confirm that a rule bound here actually matches a quote. Shipping this tab is what makes the rule rung reachable by a merchant, so **that run should happen before this lands, not after.** Say so in the PR.

**No per-customer-group pin.** Customer group is a checkout-scope condition in the rule builder, so it belongs in the rules grid. A second pin list keyed on groups would be a rung the rule rung already covers.
