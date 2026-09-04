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

assert.equal(formatPercent(7.9495), '7.9%');
assert.equal(formatPercent(null), '–');
assert.equal(formatDuration(0), '0 ms');
assert.equal(formatDuration(999), '999 ms');
assert.equal(formatDuration(22556), '22.6 s');
assert.equal(formatDuration(null), '–');

console.log('decision.ts: ok');
