/**
 * Self-check for decision.ts. No test runner: the project has no JS toolchain,
 * and one function does not justify adding one.
 *
 *     node src/Resources/app/administration/src/module/merchant-quote-agent/decision.check.mjs
 *
 * The fixtures are verbatim `interpreted_asks` values out of the decision
 * table — both shapes, because both are in it. The regression this guards is
 * the one that shipped: reading only one shape rendered the buyer's ask as
 * nothing on every current record.
 */

import assert from 'node:assert/strict';
import {
    answeredTheBuyer,
    askItems,
    askSummary,
    disposition,
    foldToQuotes,
    formatDuration,
    formatPercent,
    outcomeVariant,
} from './decision.ts';

/** $tc/$t return the last path segment, so assertions read as labels. */
const vm = {
    $tc: (key) => key.split('.').pop(),
    $t: (key, values) => `${values.count} ${key.split('.').pop()}`,
};

const current = {
    price: { bestPriceRequested: false, additionalDiscountPercent: 8 },
    structural: { addProducts: [], lineChanges: [], validityUntilIsoDate: null },
    negotiation: {
        bundle: { requested: false },
        payment: { requestedTerm: null, requestedNetDays: null, requestedDepositPercent: null },
        delivery: { expedited: false, freeShipping: false, shippingCostNet: null, requestedLeadTimeDays: 14 },
    },
    humanReviewRequests: [],
    clarificationQuestions: [],
};

const legacy = { lines: [{ quantity: 10, targetUnitPriceNet: 760.0 }], targetDiscountPercent: 5.0 };

// The shape every record written since the extract rewrite carries.
assert.deepEqual(askItems(vm, current), [
    { label: 'discount', value: '8.0%' },
    { label: 'leadTime', value: '14 days' },
]);

// The shape the pages used to read, still present on 2026-09-02 rows.
assert.deepEqual(askItems(vm, legacy), [
    { label: 'discount', value: '5.0%' },
    { label: 'targetPrice #1', value: '760.00 EUR' },
    { label: 'quantity #1', value: '10' },
]);

// `false` is a recorded answer, not an ask: flags only surface when true.
assert.deepEqual(askItems(vm, { price: { bestPriceRequested: true } }), [
    { label: 'bestPrice', value: 'requested' },
]);

// A null/absent block must not throw its way out of a table cell.
assert.deepEqual(askItems(vm, null), []);
assert.deepEqual(askItems(vm, {}), []);
assert.deepEqual(askItems(vm, { negotiation: null, structural: null, price: null }), []);

assert.equal(askSummary(vm, null), '–');
assert.equal(askSummary(vm, current), 'discount: 8.0% · leadTime: 14 days');
assert.equal(askSummary(vm, legacy), 'discount: 5.0% · targetPrice #1: 760.00 EUR +1');

// The vocabulary the backend actually writes, plus the value it used to.
assert.equal(outcomeVariant('offered'), 'positive');
assert.equal(outcomeVariant('countered'), 'positive');
assert.equal(outcomeVariant('replied'), 'positive');
assert.equal(outcomeVariant('escalated'), 'critical');
assert.equal(outcomeVariant('nothing_to_do'), 'neutral');
assert.equal(outcomeVariant('clarified'), 'info');
assert.equal(outcomeVariant(null), 'neutral');
assert.equal(outcomeVariant('something_new'), 'neutral');

assert.equal(answeredTheBuyer('offered'), true);
assert.equal(answeredTheBuyer('countered'), true);
assert.equal(answeredTheBuyer('replied'), true);
assert.equal(answeredTheBuyer('escalated'), false);
assert.equal(answeredTheBuyer('nothing_to_do'), false);
assert.equal(answeredTheBuyer(null), false);

// Every outcome lands in exactly one disposition, and an unknown one is
// visible rather than quietly counted as "no action".
assert.equal(disposition('offered'), 'answered');
assert.equal(disposition('countered'), 'answered');
assert.equal(disposition('replied'), 'answered');
assert.equal(disposition('escalated'), 'needsReview');
assert.equal(disposition('clarified'), 'awaitingBuyer');
assert.equal(disposition('nothing_to_do'), 'noAction');
assert.equal(disposition('some_future_outcome'), 'other');
assert.equal(disposition(null), 'other');

// #1017 as it sits in the table: three passes, newest first. The fold keeps the
// newest as the quote's state, counts the rounds, and takes the quote's own
// starting value rather than the latest pass's already-discounted one.
const passes = [
    { quoteId: 'q1', quoteNumber: '1017', outcome: 'offered', totalNetBefore: 84.03 },
    { quoteId: 'q1', quoteNumber: '1017', outcome: 'escalated', totalNetBefore: 91.34 },
    { quoteId: 'q1', quoteNumber: '1017', outcome: 'nothing_to_do', totalNetBefore: null },
    { quoteId: 'q2', quoteNumber: '1018', outcome: 'escalated', totalNetBefore: 50 },
];
const folded = foldToQuotes(passes);

assert.equal(folded.length, 2, 'Three passes on one quote must fold to one row.');
assert.equal(folded[0].quoteNumber, '1017');
assert.equal(folded[0].rounds, 3);
assert.equal(folded[0].disposition, 'answered', 'The newest pass decides the state.');
assert.equal(folded[0].netBefore, 91.34, 'The quote value is the highest seen, not the latest.');
assert.equal(folded[0].latest.outcome, 'offered');
assert.equal(folded[1].rounds, 1);
assert.equal(folded[1].disposition, 'needsReview');
// Newest-activity-first order survives the fold.
assert.deepEqual(folded.map((q) => q.quoteNumber), ['1017', '1018']);
assert.deepEqual(foldToQuotes([]), []);

// The partition is exhaustive: every folded quote counts once, so the parts
// always sum to the whole. This is the property the old figures broke.
const counts = folded.reduce((acc, q) => ({ ...acc, [q.disposition]: (acc[q.disposition] ?? 0) + 1 }), {});
assert.equal(Object.values(counts).reduce((a, b) => a + b, 0), folded.length);

assert.equal(formatPercent(7.9495), '7.9%');
assert.equal(formatPercent(null), '–');
assert.equal(formatDuration(0), '0 ms');
assert.equal(formatDuration(999), '999 ms');
assert.equal(formatDuration(22556), '22.6 s');
assert.equal(formatDuration(null), '–');

console.log('decision.ts: ok');
