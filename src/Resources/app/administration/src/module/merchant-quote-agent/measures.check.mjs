/**
 * Self-check for measures.ts. Same arrangement as decision.check.mjs — no test
 * runner, because the project has no JS toolchain and these are pure
 * functions.
 *
 *     node src/Resources/app/administration/src/module/merchant-quote-agent/measures.check.mjs
 */

import assert from 'node:assert/strict';
import {
    autoExecutionRate,
    dealCycleTime,
    escalationResolution,
    formatSpan,
    priceRetention,
    splitDeals,
    tokensPerNegotiation,
    valueRange,
    withinRange,
} from './measures.ts';
import { foldToQuotes } from './decision.ts';

const iso = (day, hour = 0) => `2026-09-${String(day).padStart(2, '0')}T${String(hour).padStart(2, '0')}:00:00.000+00:00`;

// ---------------------------------------------------------------- auto-execution

// The denominator is every quote serviced, not only concluded ones.
// Restricting it to concluded negotiations would exclude unresolved
// escalations, so a shop with ten quotes stuck in the review queue would
// report 100% auto-execution.
assert.deepEqual(autoExecutionRate([]), { rate: null, escalated: 0, total: 0 });
assert.deepEqual(
    autoExecutionRate([{ escalated: false }, { escalated: false }, { escalated: true }, { escalated: false }]),
    { rate: 75, escalated: 1, total: 4 },
);
assert.deepEqual(autoExecutionRate([{ escalated: true }]), { rate: 0, escalated: 1, total: 1 });

// ------------------------------------------------------------------- escalations

const passes = [
    // resolved in 4 hours
    { outcome: 'escalated', createdAt: iso(1, 10), resolvedAt: iso(1, 14) },
    // resolved in 8 hours
    { outcome: 'escalated', createdAt: iso(2, 8), resolvedAt: iso(2, 16) },
    // still open
    { outcome: 'escalated', createdAt: iso(3, 9), resolvedAt: null },
    // not an escalation at all
    { outcome: 'offered', createdAt: iso(3, 9), resolvedAt: null },
];

const sixHours = 6 * 3600000;

assert.deepEqual(escalationResolution(passes, null), {
    meanMs: sixHours,
    measured: 2,
    open: 1,
    withinSla: null,
});

// One of the two measured escalations beat a 6-hour SLA.
assert.deepEqual(escalationResolution(passes, 6), {
    meanMs: sixHours,
    measured: 2,
    open: 1,
    withinSla: 1,
});

assert.deepEqual(escalationResolution([], 24), { meanMs: null, measured: 0, open: 0, withinSla: 0 });

// An unresolved escalation reads as open, never as zero duration.
assert.deepEqual(escalationResolution([{ outcome: 'escalated', createdAt: iso(1, 10), resolvedAt: null }], 24), {
    meanMs: null,
    measured: 0,
    open: 1,
    withinSla: 0,
});

// A resolvedAt before the escalation is corrupt, not a negative duration.
assert.equal(
    escalationResolution([{ outcome: 'escalated', createdAt: iso(2, 10), resolvedAt: iso(1, 10) }], null).measured,
    0,
);

// ------------------------------------------------------------------------ deals

const folded = foldToQuotes([
    { id: 'p1', quoteId: 'agent-1', outcome: 'offered', totalNetBefore: 1000, totalNetAfter: 900, terminalState: 'accepted', createdAt: iso(1, 12) },
    { id: 'p2', quoteId: 'agent-2', outcome: 'escalated', totalNetBefore: 5000, terminalState: 'accepted', createdAt: iso(2, 12) },
]);
const foldedByQuote = new Map(folded.map((quote) => [quote.quoteId, quote]));

const quoteRows = [
    // agent-negotiated: 10% off, RFQ to order in 24h
    { id: 'agent-1', amountNet: 900, requestedAt: iso(1, 10), createdAt: iso(1, 9), orderId: 'o1', totalDiscount: 0, totalLineItemDiscount: 100 },
    // agent-touched but only ever escalated: a human set the price
    { id: 'agent-2', amountNet: 5000, requestedAt: iso(2, 10), createdAt: iso(2, 9), orderId: 'o2', totalDiscount: 0, totalLineItemDiscount: 0 },
    // untouched baseline, inside the agent value range: 20% off, 72h
    { id: 'base-1', amountNet: 800, requestedAt: iso(1, 10), createdAt: iso(1, 9), orderId: 'o3', totalDiscount: 200, totalLineItemDiscount: 0 },
    // untouched baseline, far outside the agent value range
    { id: 'base-2', amountNet: 90000, requestedAt: iso(1, 10), createdAt: iso(1, 9), orderId: 'o4', totalDiscount: 45000, totalLineItemDiscount: 0 },
];

const orderDates = new Map([
    ['o1', iso(2, 10)],
    ['o2', iso(3, 10)],
    ['o3', iso(4, 10)],
    ['o4', iso(2, 10)],
]);

const { agent, baseline } = splitDeals(quoteRows, orderDates, foldedByQuote);

assert.deepEqual(agent.map((d) => d.quoteId), ['agent-1', 'agent-2']);
assert.deepEqual(baseline.map((d) => d.quoteId), ['base-1', 'base-2']);

// requestedAt wins over createdAt where it exists.
assert.equal(agent[0].submittedAt, iso(1, 10));
// ...and falls back where it does not, which is every quote on 7.12.
assert.equal(
    splitDeals([{ id: 'x', amountNet: 10, createdAt: iso(5, 8), orderId: null }], new Map(), new Map())
        .baseline[0].submittedAt,
    iso(5, 8),
);

// The agent's discount comes from our own records, so it is version-proof.
assert.equal(agent[0].agentDiscount, 10);
// A quote that escalated and was answered by a human has no answered pass, so
// there is no agent-set price to measure.
assert.equal(agent[1].agentDiscount, null);
const editedDraft = foldToQuotes([{
    id: 'edited-pass', quoteId: 'edited-quote', outcome: 'offered', reviewStatus: 'sent',
    totalNetBefore: 100, totalNetAfter: 90, sentChanges: { totalNet: 80 }, createdAt: iso(2, 12),
}]);
const editedDeal = splitDeals(
    [{ id: 'edited-quote', amountNet: 80, createdAt: iso(2, 10), orderId: 'edited-order' }],
    new Map([['edited-order', iso(3, 10)]]),
    new Map(editedDraft.map((quote) => [quote.quoteId, quote])),
).agent[0];
assert.equal(editedDeal.agentDiscount, 20, 'A sent draft uses the merchant-edited total, not the agent proposal.');
// The baseline's discount comes from the quote's own fields.
assert.equal(baseline[0].baselineDiscount, 20);
// A missing totalLineItemDiscount is 0, not NaN — that field does not exist on
// SwagCommercial 7.12.
assert.equal(
    splitDeals([{ id: 'y', amountNet: 90, totalDiscount: 10, orderId: null }], new Map(), new Map())
        .baseline[0].baselineDiscount,
    10,
);

// ----------------------------------------------------------------- value range

// The range is read off amountNet on BOTH sides. Reading the agent side from
// the decision record's pre-negotiation netBefore would bias the baseline
// upward by exactly the discount being measured.
assert.deepEqual(valueRange(agent), { min: 900, max: 5000 });
assert.equal(valueRange([]), null);
assert.deepEqual(withinRange(baseline, valueRange(agent)).map((d) => d.quoteId), []);
assert.deepEqual(withinRange(baseline, { min: 100, max: 1000 }).map((d) => d.quoteId), ['base-1']);
// No agent deals means no range, and comparing against everything would be a
// lie rather than a fallback.
assert.deepEqual(withinRange(baseline, null), []);

// -------------------------------------------------------------- the two ratios

// base-1 is 800 net, below the agent range's 900 floor, so nothing is
// comparable and the baseline is unavailable rather than misleading.
assert.deepEqual(priceRetention(agent, baseline), {
    agentDiscount: 10,
    baselineDiscount: null,
    comparable: 0,
});

const wideAgent = [...agent, { quoteId: 'agent-3', amountNet: 700, submittedAt: iso(1, 10), confirmedAt: iso(1, 22), agentDiscount: 4, baselineDiscount: null }];

assert.deepEqual(priceRetention(wideAgent, baseline), {
    agentDiscount: 7,
    baselineDiscount: 20,
    comparable: 1,
});

// agent-1 took 24h; agent-2 took 24h; agent-3 took 12h.
assert.deepEqual(dealCycleTime(agent, baseline), {
    agentMs: 24 * 3600000,
    baselineMs: null,
    comparable: 0,
});
assert.deepEqual(dealCycleTime(wideAgent, baseline), {
    agentMs: 20 * 3600000,
    baselineMs: 72 * 3600000,
    comparable: 1,
});

// A deal with no order date has no cycle time and must not read as zero.
assert.equal(
    dealCycleTime([{ quoteId: 'z', amountNet: 100, submittedAt: iso(1, 10), confirmedAt: null, agentDiscount: null, baselineDiscount: null }], []).agentMs,
    null,
);
assert.deepEqual(dealCycleTime([], []), { agentMs: null, baselineMs: null, comparable: 0 });

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

// ------------------------------------------------------------------ formatting

assert.equal(formatSpan(null), '–');
assert.equal(formatSpan(45 * 60000), '45 min');
assert.equal(formatSpan(4 * 3600000), '4.0 h');
assert.equal(formatSpan(36 * 3600000), '1.5 d');

// eslint-disable-next-line no-console
console.log('measures.check.mjs: all assertions passed');
