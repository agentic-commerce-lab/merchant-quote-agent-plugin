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

// ------------------------------------------------- shared baseline, overlapping bands

// Two strategies, each negotiating one quote, both landing at the same net
// value (900) — so their value bands overlap completely. A quote NOT in a
// group's own fold is not necessarily untouched: it can be another
// strategy's agent-negotiated deal. If a group's baseline were built from
// "everything not in this group's fold" (the old, wrong shape), v1's row
// would pick up v2's own agent quote (q4, a steep 25% quote-level discount)
// as if it were a human-only baseline deal, and vice versa. The fix computes
// the agent/baseline split ONCE over every pass, so only q5 — untouched by
// either strategy — ever lands on the baseline side, and both rows share it.
const bandPasses = [
    { id: 'b1', quoteId: 'q1', outcome: 'offered', strategyVersionId: 'v1', createdAt: iso(2), totalNetBefore: 1000, totalNetAfter: 900 },
    { id: 'b4', quoteId: 'q4', outcome: 'offered', strategyVersionId: 'v2', createdAt: iso(2), totalNetBefore: 1200, totalNetAfter: 900 },
];
const bandQuoteRows = [
    { id: 'q1', amountNet: 900, requestedAt: iso(1), createdAt: iso(1), orderId: null, totalDiscount: 0, totalLineItemDiscount: 100 },
    { id: 'q4', amountNet: 900, requestedAt: iso(1), createdAt: iso(1), orderId: null, totalDiscount: 0, totalLineItemDiscount: 300 },
    { id: 'q5', amountNet: 900, requestedAt: iso(1), createdAt: iso(1), orderId: null, totalDiscount: 0, totalLineItemDiscount: 100 },
];
const bandRows = strategyRows(bandPasses, bandQuoteRows, new Map(), null, () => null);
const [v1Row, v2Row] = bandRows;

// q4 (v2's own agent quote, 25% quote-level discount) must NOT appear in v1's
// baseline — only q5 (10%, genuinely untouched) does.
assert.equal(v1Row.priceRetention.comparable, 1);
assert.equal(v1Row.priceRetention.baselineDiscount, 10);

// Symmetrically, q1 (v1's own agent quote) must not contaminate v2's baseline.
assert.equal(v2Row.priceRetention.comparable, 1);
assert.equal(v2Row.priceRetention.baselineDiscount, 10);

// eslint-disable-next-line no-console
console.log('strategy-measures.check.mjs: all assertions passed');
