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
    DISPOSITION_CLASSES,
    answeredTheBuyer,
    askItems,
    askSummary,
    conversation,
    disposition,
    dispositionVariant,
    escalationExplanation,
    foldToQuotes,
    formatDuration,
    formatPercent,
    outcomeVariant,
} from './decision.ts';

/**
 * $tc/$t return the last path segment, so assertions read as labels. $t also
 * echoes the interpolated values, which is what lets the escalation-sentence
 * assertions below check that every placeholder is actually filled — a snippet
 * that silently drops one would still return a string.
 */
const vm = {
    $tc: (key) => key.split('.').pop(),
    $t: (key, values) => `${Object.values(values ?? {}).join(' / ')} ${key.split('.').pop()}`,
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

// The order the customer placed outranks whatever the last pass did: a quote
// that escalated and was then ordered anyway is a win, not a queue item.
assert.equal(disposition('offered', 'accepted'), 'orderPlaced');
assert.equal(disposition('escalated', 'accepted'), 'orderPlaced');
assert.equal(disposition('nothing_to_do', 'accepted'), 'orderPlaced');
// Every other terminal state leaves the pass's own outcome standing. None of
// them means an order — verified against the shop: of 75 quotes, the five
// `accepted` ones are exactly the five carrying an order id.
assert.equal(disposition('escalated', 'declined'), 'needsReview');
assert.equal(disposition('offered', 'expired'), 'answered');
assert.equal(disposition('offered', 'cancelled'), 'answered');
assert.equal(disposition('offered', 'withdrawn'), 'answered');
assert.equal(disposition('offered', null), 'answered');

// Label and hue come from the same value. The pair below is the regression:
// the last pass escalated, the customer ordered anyway, and the badge must not
// say "Order placed" in the critical hue.
assert.equal(dispositionVariant(disposition('escalated', 'accepted')), 'positive');
assert.equal(dispositionVariant(disposition('escalated', null)), 'critical');
assert.equal(dispositionVariant('answered'), 'positive');
assert.equal(dispositionVariant('awaitingBuyer'), 'info');
assert.equal(dispositionVariant('noAction'), 'neutral');
// `success` is not in Meteor's badge set and renders unstyled, so every
// disposition must map to one that exists.
const METEOR_VARIANTS = ['neutral', 'info', 'positive', 'critical', 'attention'];
DISPOSITION_CLASSES.forEach((key) => {
    assert.ok(METEOR_VARIANTS.includes(dispositionVariant(key)), `${key} has no Meteor badge variant`);
});
assert.equal(dispositionVariant('something_new'), 'neutral');
assert.equal(dispositionVariant(null), 'neutral');

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

// The terminal state is found on ANY pass of the quote, not only the newest.
// TerminalOutcomeWriter stamps whichever record was newest when the customer
// ordered, and a pass already in flight then inserts a newer one behind it —
// reading only the newest record loses the order on exactly that quote.
const orderedLate = foldToQuotes([
    { quoteId: 'q3', quoteNumber: '1019', outcome: 'nothing_to_do', totalNetBefore: null, terminalState: null },
    { quoteId: 'q3', quoteNumber: '1019', outcome: 'offered', totalNetBefore: 500, terminalState: 'accepted', terminalAt: '2026-09-08T10:00:00+00:00' },
]);

assert.equal(orderedLate.length, 1);
assert.equal(orderedLate[0].disposition, 'orderPlaced');
assert.equal(orderedLate[0].terminalState, 'accepted');
assert.equal(orderedLate[0].terminalAt, '2026-09-08T10:00:00+00:00');
assert.equal(orderedLate[0].latest.outcome, 'nothing_to_do', 'The newest pass is still the quote latest.');

// A quote with no terminal state anywhere keeps its pass-derived class.
assert.equal(
    foldToQuotes([{ quoteId: 'q4', outcome: 'escalated', totalNetBefore: 10 }])[0].disposition,
    'needsReview',
);

// The partition is exhaustive: every folded quote counts once, so the parts
// always sum to the whole. This is the property the old figures broke.
const counts = folded.reduce((acc, q) => ({ ...acc, [q.disposition]: (acc[q.disposition] ?? 0) + 1 }), {});
assert.equal(Object.values(counts).reduce((a, b) => a + b, 0), folded.length);

// The escalation sentence carries the pass's own numbers, and is composed from
// the row: nothing about it is stored, so it works on rows already in the table.
const overCap = escalationExplanation(vm, {
    escalationReason: 'discount_limit_exceeded',
    maxDiscountPercent: 5,
    interpretedAsks: { price: { additionalDiscountPercent: 12 } },
});

assert.equal(overCap, '12.0% / 5.0% / – / – discount_limit_exceeded', 'The asked and cap percentages must both reach the sentence.');

// The legacy ask shape has to reach it too — the table holds both.
assert.equal(
    escalationExplanation(vm, {
        escalationReason: 'discount_limit_exceeded',
        maxDiscountPercent: 5,
        interpretedAsks: { targetDiscountPercent: 9 },
    }),
    '9.0% / 5.0% / – / – discount_limit_exceeded',
);

// A buyer who asked with a per-line target rather than a percentage still gets
// a sentence, with a wording that does not claim a number it does not have.
assert.equal(
    escalationExplanation(vm, {
        escalationReason: 'needs_human_review',
        maxDiscountPercent: 5,
        interpretedAsks: { structural: { lineChanges: [{ targetUnitPriceNet: 700 }] } },
    }),
    'anUnstatedAmount / 5.0% / – / – needs_human_review',
);

// The value ceiling sentence needs the quote's value in its own currency.
assert.equal(
    escalationExplanation(vm, {
        escalationReason: 'quote_value_limit_exceeded',
        maxDiscountPercent: 5,
        totalNetBefore: 84000,
        currencyIso: 'USD',
        interpretedAsks: null,
    }),
    'anUnstatedAmount / 5.0% / 84000.00 USD / USD quote_value_limit_exceeded',
);

// A pass that did not escalate has nothing to explain.
assert.equal(escalationExplanation(vm, { escalationReason: null }), null);

// The conversation is attributed by authorship the way the backend writes it:
// any of createdById / customerId / employeeId means a person, none means the
// agent. Oldest first, and an empty comment is not a message.
const thread = conversation([
    { id: 'c2', comment: 'We can bring this down by 5%.', createdAt: '2026-09-08T09:05:00+00:00' },
    { id: 'c1', comment: ' Can you do 12% on this? ', customerId: 'cust-1', createdAt: '2026-09-08T09:00:00+00:00' },
    { id: 'c3', comment: '   ', customerId: 'cust-1', createdAt: '2026-09-08T09:10:00+00:00' },
    { id: 'c4', comment: 'Still too high.', employeeId: 'emp-1', createdAt: '2026-09-08T09:15:00+00:00' },
    { id: 'c5', comment: 'Noted internally.', createdById: 'user-1', createdAt: '2026-09-08T09:20:00+00:00' },
]);

assert.deepEqual(thread.map((m) => m.id), ['c1', 'c2', 'c4', 'c5'], 'Oldest first, blank dropped.');
assert.deepEqual(thread.map((m) => m.fromAgent), [false, true, false, false]);
assert.equal(thread[0].text, 'Can you do 12% on this?', 'The stored text is trimmed for display.');

assert.deepEqual(conversation(null), []);
assert.deepEqual(conversation([]), []);

assert.equal(formatPercent(7.9495), '7.9%');
assert.equal(formatPercent(null), '–');
assert.equal(formatDuration(0), '0 ms');
assert.equal(formatDuration(999), '999 ms');
assert.equal(formatDuration(22556), '22.6 s');
assert.equal(formatDuration(null), '–');

console.log('decision.ts: ok');
