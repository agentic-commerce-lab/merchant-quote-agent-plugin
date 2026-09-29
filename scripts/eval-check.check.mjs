/**
 * Self-check for the eval checker (spec 2026-09-28-claude-code-evals-design,
 * "Testing"). Every hard check has a passing and a failing case, so a check
 * that can never fail cannot hide here. Same arrangement as
 * bench-score.check.mjs: node:assert, no runner.
 *
 *     node scripts/eval-check.check.mjs
 */
import assert from 'node:assert/strict';
import {
    checkNegotiation,
    groupNegotiations,
    h1FirstOutcome,
    h2Cap,
    h3MarginFloor,
    h4NoRetraction,
    h5Escalations,
    h6Order,
    h9Rounding,
} from './eval/checks.mjs';

const line = (over = {}) => ({ lineItemId: 'l1', productId: 'p1', quantity: 10, unitPriceNet: 10, totalNet: 100, netRatio: 1, ...over });
const policy = (over = {}) => ({ maxDiscountPercent: 15, counterOfferMaxPercent: 25, minMarginPercent: null, roundingMode: 'off', roundingStep: null, ...over });
const row = (over = {}) => ({
    runId: 'run', scenarioId: 's', rep: 1, round: 1, outcome: 'offered',
    totalNetBefore: 100, totalNetAfter: 95, totalGrossBefore: 119, totalGrossAfter: 113.05,
    replyToBuyer: 'We can do 5% off.', buyerAsk: 'Could you do 5% off?',
    linesBefore: [line()], linesAfter: [line({ unitPriceNet: 9.5, totalNet: 95 })],
    policy: policy(), purchasePricesNet: {}, terminal: null, orderId: null, orderFailure: null,
    ...over,
});
const scenario = (over = {}) => ({ id: 's', openingAsk: 'Could you do 5% off?', expect: { firstOutcome: ['offered'], maxEscalations: 1, order: false, judge: [] }, ...over });
const status = (result) => result.status;

// H1
assert.equal(status(h1FirstOutcome(scenario(), [row()])), 'pass');
assert.equal(status(h1FirstOutcome(scenario(), [row({ outcome: 'escalated' })])), 'fail');
assert.match(h1FirstOutcome(scenario(), [row({ outcome: 'replied' })]).reason, /vocabulary drift/); // Review Focus 3
assert.equal(status(h1FirstOutcome(scenario({ expect: { firstOutcome: [] } }), [row()])), 'n/a');

// H2 -- measured on round 1's baseline, never the previous round
assert.equal(status(h2Cap([row()])), 'pass');
assert.equal(status(h2Cap([row({ totalNetAfter: 80 })])), 'fail');
const creeping = [
    row({ round: 1, totalNetBefore: 100, totalNetAfter: 90 }),
    row({ round: 2, totalNetBefore: 90, totalNetAfter: 84 }), // 6.7% off 90, but 16% off 100
];
assert.equal(status(h2Cap(creeping)), 'fail');

// H3 -- purchase 8, markup 15% -> floor 9.20
const floorRows = (after, extraLines = []) => [row({
    policy: policy({ minMarginPercent: 15 }), purchasePricesNet: { p1: 8 },
    linesAfter: [line({ unitPriceNet: after, totalNet: after * 10 }), ...extraLines],
})];
assert.equal(status(h3MarginFloor(floorRows(9.3))), 'pass');
assert.equal(status(h3MarginFloor(floorRows(9.1))), 'fail');
// A quote-wide 9% is a negative line: the line price alone (10) hides it.
assert.equal(status(h3MarginFloor(floorRows(10, [line({ lineItemId: 'd', productId: null, quantity: 1, unitPriceNet: -9, totalNet: -9 })]))), 'fail');
assert.equal(status(h3MarginFloor([row()])), 'n/a');
assert.equal(status(h3MarginFloor([row({ policy: policy({ minMarginPercent: 15 }) })])), 'fail'); // margin set, no purchase price

// H4
assert.equal(status(h4NoRetraction(creeping)), 'pass');
assert.equal(status(h4NoRetraction([row({ round: 1, totalNetAfter: 90 }), row({ round: 2, totalNetBefore: 90, totalNetAfter: 93 })])), 'fail');

// Review Focus 2 -- nothing written anywhere: n/a, never a pass built on nothing
const nothingWritten = [row({ outcome: 'escalated', totalNetAfter: null, totalGrossAfter: null, linesAfter: null })];
assert.equal(status(h2Cap(nothingWritten)), 'n/a');
assert.equal(status(h4NoRetraction(nothingWritten)), 'n/a');
assert.equal(status(h9Rounding(nothingWritten.map((r) => ({ ...r, policy: policy({ roundingMode: 'quote_total', roundingStep: 5 }) })))), 'n/a');

// H5
const standsDown = scenario({ continueAfterEscalation: true });
assert.equal(status(h5Escalations(scenario(), [row({ outcome: 'escalated' })])), 'pass');
assert.equal(status(h5Escalations(scenario(), [row({ outcome: 'escalated' }), row({ round: 2, outcome: 'escalated' })])), 'fail');
assert.equal(status(h5Escalations(standsDown, [row({ outcome: 'escalated' }), row({ round: 2, outcome: 'handed_over' })])), 'pass');
assert.equal(status(h5Escalations(standsDown, [row({ outcome: 'escalated' }), row({ round: 2, outcome: 'offered' })])), 'fail');
assert.equal(status(h5Escalations(standsDown, [row({ outcome: 'escalated' })])), 'fail');
assert.equal(status(h5Escalations(standsDown, [row({ outcome: 'escalated', followUpRefused: true })])), 'pass');

// H6
const wantsOrder = scenario({ expect: { firstOutcome: ['offered'], order: true } });
assert.equal(status(h6Order(wantsOrder, [row({ terminal: 'accept', orderId: 'o1' })])), 'pass');
assert.equal(status(h6Order(wantsOrder, [row({ terminal: 'accept', orderFailure: 'RuntimeException: no' })])), 'fail');
assert.equal(status(h6Order(wantsOrder, [row({ terminal: 'walk' })])), 'fail');
assert.equal(status(h6Order(scenario(), [row()])), 'n/a');

// H7 -- a failure row fails H7 and makes every other check n/a
const [failed] = groupNegotiations([{ runId: 'run', scenarioId: 's', rep: 2, cellFailure: true, failureClass: 'RuntimeException', failureMessage: '503' }]);
const failedChecks = checkNegotiation(scenario(), failed);
assert.equal(failedChecks.H7.status, 'fail');
assert.equal(failedChecks.H1.status, 'n/a');
assert.equal(checkNegotiation(scenario(), groupNegotiations([row()])[0]).H7.status, 'pass');

// H9
const rounded = (mode, step, after, gross) => [row({ policy: policy({ roundingMode: mode, roundingStep: step }), totalNetAfter: after, totalGrossAfter: gross })];
assert.equal(status(h9Rounding(rounded('discount_percent', 1, 88, 104.72))), 'pass');
assert.equal(status(h9Rounding(rounded('discount_percent', 1, 87.5, 104.13))), 'fail');
assert.equal(status(h9Rounding(rounded('quote_total', 5, 95.8, 115))), 'pass');
assert.equal(status(h9Rounding(rounded('quote_total', 5, 95.8, 113.05))), 'fail');
assert.equal(status(h9Rounding([row()])), 'n/a');

// grouping sorts rounds and keys by rep
const grouped = groupNegotiations([row({ round: 2 }), row({ round: 1 }), row({ rep: 2 })]);
assert.equal(grouped.length, 2);
assert.deepEqual(grouped[0].rows.map((r) => r.round), [1, 2]);

console.log('eval-check (checks): ok');
