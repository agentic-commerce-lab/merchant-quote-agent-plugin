# Refine Quote History UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refine the quote servicing history timeline headers on the quote agent detail page to show `#1 Quote Agent [Badge]` instead of backend trigger reasons, and remove `rawProposal` from the technical details spoiler.

**Architecture:** Update Vue template and TypeScript controller in `merchant-quote-agent-detail`, adjust SCSS for index tag styling with Meteor design tokens, add `agentTitle` snippet in English and German, and verify via `decision.check.mjs`.

**Tech Stack:** Shopware Administration (Vue.js / Meteor component library, SCSS, Twig templates, TypeScript), Node test scripts.

---

### Task 1: Snippets and Assertion Coverage

**Files:**
- Modify: `src/Resources/app/administration/src/module/merchant-quote-agent/snippet/en.json`
- Modify: `src/Resources/app/administration/src/module/merchant-quote-agent/snippet/de.json`
- Modify: `src/Resources/app/administration/src/module/merchant-quote-agent/decision.check.mjs`

- [ ] **Step 1: Add snippet check assertion to `decision.check.mjs`**

Add assertion in `decision.check.mjs` to verify that `agentTitle` exists in both `en` and `de` snippets under `detail`:

```javascript
for (const snippet of snippets) {
    assert.ok(snippet.detail.agentTitle, "detail.agentTitle snippet missing");
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `node src/Resources/app/administration/src/module/merchant-quote-agent/decision.check.mjs`
Expected: FAIL with "detail.agentTitle snippet missing"

- [ ] **Step 3: Add `agentTitle` to `en.json` and `de.json`**

In `src/Resources/app/administration/src/module/merchant-quote-agent/snippet/en.json` under `"merchant-quote-agent": { "detail": { ... } }`:
```json
"agentTitle": "Quote Agent",
```

In `src/Resources/app/administration/src/module/merchant-quote-agent/snippet/de.json` under `"merchant-quote-agent": { "detail": { ... } }`:
```json
"agentTitle": "Quote Agent",
```

- [ ] **Step 4: Run test to verify it passes**

Run: `node src/Resources/app/administration/src/module/merchant-quote-agent/decision.check.mjs`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Resources/app/administration/src/module/merchant-quote-agent/snippet/en.json \
        src/Resources/app/administration/src/module/merchant-quote-agent/snippet/de.json \
        src/Resources/app/administration/src/module/merchant-quote-agent/decision.check.mjs
git commit -m "feat(admin): add agentTitle snippet for quote history timeline"
```

---

### Task 2: Update Timeline Header Template & SCSS Tag Styling

**Files:**
- Modify: `src/Resources/app/administration/src/module/merchant-quote-agent/page/merchant-quote-agent-detail/merchant-quote-agent-detail.html.twig:148-154`
- Modify: `src/Resources/app/administration/src/module/merchant-quote-agent/merchant-quote-agent.scss:256-261`

- [ ] **Step 1: Update Twig header template**

In `src/Resources/app/administration/src/module/merchant-quote-agent/page/merchant-quote-agent-detail/merchant-quote-agent-detail.html.twig`, replace:
```twig
                            <div class="mqa-entry__header">
                                <span class="mqa-run__index">{{ entry.run.index }}</span>
                                <h4 class="mqa-entry__title">{{ entry.run.title }}</h4>
                                <mt-badge :variant="entry.run.outcomeVariant">{{ entry.run.outcomeLabel }}</mt-badge>
                                <span class="mqa-entry__time">{{ entry.run.timestamp }}</span>
                            </div>
```
with:
```twig
                            <div class="mqa-entry__header">
                                <span class="mqa-run__index">#{{ entry.run.index }}</span>
                                <h4 class="mqa-entry__title">{{ $tc('merchant-quote-agent.detail.agentTitle') }}</h4>
                                <mt-badge :variant="entry.run.outcomeVariant">{{ entry.run.outcomeLabel }}</mt-badge>
                                <span class="mqa-entry__time">{{ entry.run.timestamp }}</span>
                            </div>
```

- [ ] **Step 2: Update SCSS for index tag styling**

In `src/Resources/app/administration/src/module/merchant-quote-agent/merchant-quote-agent.scss`, replace:
```scss
.mqa-run__index {
    color: var(--color-text-secondary-default);
    font-size: 13px;
    font-variant-numeric: tabular-nums;
}
```
with:
```scss
.mqa-run__index {
    color: var(--color-text-secondary-default);
    font-size: 12px;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    background: var(--color-elevation-surface-sunken);
    padding: 1px 6px;
    border-radius: 4px;
}
```

- [ ] **Step 3: Verify syntax and linting**

Run: `node src/Resources/app/administration/src/module/merchant-quote-agent/decision.check.mjs`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add src/Resources/app/administration/src/module/merchant-quote-agent/page/merchant-quote-agent-detail/merchant-quote-agent-detail.html.twig \
        src/Resources/app/administration/src/module/merchant-quote-agent/merchant-quote-agent.scss
git commit -m "feat(admin): update timeline pass header to #index and Quote Agent label"
```

---

### Task 3: Update Controller and Remove `rawProposal` from Technical Details

**Files:**
- Modify: `src/Resources/app/administration/src/module/merchant-quote-agent/page/merchant-quote-agent-detail/index.ts:259,327-333`

- [ ] **Step 1: Update `formatRun` and remove `rawProposal` in `index.ts`**

In `src/Resources/app/administration/src/module/merchant-quote-agent/page/merchant-quote-agent-detail/index.ts`:
1. In `formatRun(round, index)`, change `title`:
```ts
title: this.$tc('merchant-quote-agent.detail.agentTitle'),
```
2. In `technical(round)`, remove the `rawProposal` block:
```ts
            // The model's own answer, recorded on every pass since the table
            // existed and rendered nowhere. Wide because it is JSON: in a
            // 200px grid cell it reads as a column of punctuation.
            if (round.rawProposal) {
                rows.push({ key: 'rawProposal', value: round.rawProposal, mono: true, wide: true });
            }
```

- [ ] **Step 2: Run checks to verify no syntax errors or regressions**

Run: `node src/Resources/app/administration/src/module/merchant-quote-agent/decision.check.mjs`
Run: `node src/Resources/app/administration/src/module/merchant-quote-agent/measures.check.mjs`
Expected: PASS

- [ ] **Step 3: Commit**

```bash
git add src/Resources/app/administration/src/module/merchant-quote-agent/page/merchant-quote-agent-detail/index.ts
git commit -m "feat(admin): remove rawProposal from technical spoiler and use agentTitle in run model"
```

---

### Task 4: Full Quality Gate & Cleanup

**Files:**
- Run checks on repository

- [ ] **Step 1: Run composer / Mago checks**

Run: `composer run format:check && composer run lint && composer run typecheck`
Expected: PASS

- [ ] **Step 2: Clean up temporary brainstorm files and stop visual server**

Run: Stop background server and clean up temporary brainstorm files.
```bash
scripts/stop-server.sh
```

- [ ] **Step 3: Final verification**

Confirm git status is clean and all tests/checks pass.
