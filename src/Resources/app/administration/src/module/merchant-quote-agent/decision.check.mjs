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
import { readFileSync } from 'node:fs';
import { historySummary, historyReads } from './history.ts';
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
    humanReviewRequests,
    mergeStream,
    outcomeVariant,
    passNotes,
    quoteDiscountPercent,
    roundChange,
    terminalExplanation,
    writeLabels,
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

// A budget named for the whole quote sits on no line, so the chip is the only
// place it shows before the offer that answers it.
assert.deepEqual(askItems(vm, { price: { targetTotal: 2500 } }), [
    { label: 'targetTotal', value: '2500.00 EUR' },
]);

// A null/absent block must not throw its way out of a table cell.
assert.deepEqual(askItems(vm, null), []);
assert.deepEqual(askItems(vm, {}), []);
assert.deepEqual(askItems(vm, { negotiation: null, structural: null, price: null }), []);

assert.equal(askSummary(vm, null), '–');
assert.equal(askSummary(vm, current), 'discount: 8.0% · leadTime: 14 days');
assert.equal(askSummary(vm, legacy), 'discount: 5.0% · targetPrice #1: 760.00 EUR +1');

// Issue #169: the escalation surface a merchant scans (the list page's badge
// cell) reads this directly, not only askItems()'s drawer row -- so it has
// its own self-check independent of the ask-facts list above.
assert.deepEqual(humanReviewRequests(null), []);
assert.deepEqual(humanReviewRequests({}), []);
assert.deepEqual(humanReviewRequests({ humanReviewRequests: [] }), [], 'Empty for every reason but the genuine one.');
assert.deepEqual(
    humanReviewRequests({ humanReviewRequests: ['wants to speak to a person', 'asked about a custom install'] }),
    ['wants to speak to a person', 'asked about a custom install'],
);
// Non-string and empty entries are dropped rather than rendered as blanks.
assert.deepEqual(humanReviewRequests({ humanReviewRequests: ['ok', '', null, 42] }), ['ok']);

// The vocabulary the backend actually writes, plus the value it used to.
assert.equal(outcomeVariant('offered'), 'positive');
assert.equal(outcomeVariant('countered'), 'positive');
assert.equal(outcomeVariant('replied'), 'positive');
assert.equal(outcomeVariant('escalated'), 'critical');
assert.equal(outcomeVariant('nothing_to_do'), 'neutral');
assert.equal(outcomeVariant('acknowledged'), 'neutral');
// Documents intent, not a pin: outcomeVariant's own fallback for an unmapped
// value is already 'neutral' (see below and outcomeVariant's return), so this
// holds whether or not `handed_over` is in OUTCOME_VARIANTS. disposition() and
// passNotes() below are where handed_over is actually pinned.
assert.equal(outcomeVariant('handed_over'), 'neutral');
assert.equal(outcomeVariant('clarified'), 'info');
assert.equal(outcomeVariant(null), 'neutral');
assert.equal(outcomeVariant('something_new'), 'neutral');

assert.equal(answeredTheBuyer('offered'), true);
assert.equal(answeredTheBuyer('countered'), true);
assert.equal(answeredTheBuyer('replied'), true);
assert.equal(answeredTheBuyer('escalated'), false);
assert.equal(answeredTheBuyer('nothing_to_do'), false);
// Restates the quote; not an offer (mirrors NegotiationOutcome::answeredTheBuyer()).
assert.equal(answeredTheBuyer('acknowledged'), false);
// Documents intent, not a pin on THIS task: answeredTheBuyer is a plain
// allow-list with no fallback, so this passes only because `handed_over` is
// not on it today, unchanged by this task's edits. Unlike outcomeVariant
// above, it is not invariant — add `handed_over` to ANSWERED_OUTCOMES and
// this assertion fails, which is the point: it is a tripwire against that,
// not proof of it. disposition() and passNotes() below are where
// handed_over is actually pinned.
assert.equal(answeredTheBuyer('handed_over'), false);
assert.equal(answeredTheBuyer(null), false);

// Every outcome lands in exactly one disposition, and an unknown one is
// visible rather than quietly counted as "no action".
assert.equal(disposition('offered'), 'answered');
assert.equal(disposition('countered'), 'answered');
assert.equal(disposition('replied'), 'answered');
assert.equal(disposition('escalated'), 'needsReview');
assert.equal(disposition('clarified'), 'awaitingBuyer');
assert.equal(disposition('nothing_to_do'), 'noAction');
// The quote is back in `replied` with the standing offer in front of the buyer.
assert.equal(disposition('acknowledged'), 'answered');
assert.equal(disposition('handed_over'), 'noAction');
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
// A terminal state always outranks the pass outcome. Before this, only
// `accepted` did, so an escalated quote that ended declined, expired,
// cancelled or withdrawn read "Needs review" forever — and the grid now
// defaults to that filter.
assert.equal(disposition('escalated', 'declined'), 'closedNoDeal');
assert.equal(disposition('escalated', 'expired'), 'closedNoDeal');
assert.equal(disposition('escalated', 'cancelled'), 'closedNoDeal');
assert.equal(disposition('escalated', 'withdrawn'), 'closedNoDeal');
assert.equal(disposition('offered', 'declined'), 'closedNoDeal');
assert.equal(disposition('nothing_to_do', 'expired'), 'closedNoDeal');
assert.equal(disposition('offered', 'expired'), 'closedNoDeal');
assert.equal(disposition('offered', 'cancelled'), 'closedNoDeal');
assert.equal(disposition('offered', 'withdrawn'), 'closedNoDeal');
assert.equal(disposition('offered', null), 'answered');

// An escalation a human has answered is waiting on the buyer, not on the
// merchant. It leaves the review queue without needing a class of its own.
assert.equal(disposition('escalated', null, null), 'needsReview');
assert.equal(disposition('escalated', null, '2026-09-09T10:00:00.000+00:00'), 'awaitingBuyer');

// An accepted quote is an order regardless of how it got there.
assert.equal(disposition('escalated', 'accepted', '2026-09-09T10:00:00.000+00:00'), 'orderPlaced');

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
assert.equal(dispositionVariant('closedNoDeal'), 'neutral');
assert.ok(DISPOSITION_CLASSES.includes('closedNoDeal'));

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

// `escalated` is true if ANY pass escalated, not just the latest: a quote
// that escalated in round one and was answered in round two did require a
// human, and the auto-execution rate has to count it.
const twoRounds = foldToQuotes([
    { id: 'p2', quoteId: 'q9', outcome: 'offered', totalNetBefore: 100, totalNetAfter: 95, createdAt: '2026-09-02T10:00:00.000+00:00' },
    { id: 'p1', quoteId: 'q9', outcome: 'escalated', totalNetBefore: 100, createdAt: '2026-09-01T10:00:00.000+00:00' },
]);

assert.equal(twoRounds.length, 1);
assert.equal(twoRounds[0].escalated, true);
assert.equal(twoRounds[0].escalatedAt, '2026-09-01T10:00:00.000+00:00');
assert.equal(twoRounds[0].disposition, 'answered');
assert.equal(twoRounds[0].latestAnswered.id, 'p2');

// A quote that only ever escalated has no answered pass at all, which is
// what excludes it from price retention.
const onlyEscalated = foldToQuotes([
    { id: 'p1', quoteId: 'q10', outcome: 'escalated', totalNetBefore: 100, createdAt: '2026-09-01T10:00:00.000+00:00', resolvedAt: '2026-09-01T14:00:00.000+00:00' },
]);

assert.equal(onlyEscalated[0].latestAnswered, null);
assert.equal(onlyEscalated[0].resolvedAt, '2026-09-01T14:00:00.000+00:00');
assert.equal(onlyEscalated[0].disposition, 'awaitingBuyer');

// A quote that escalated twice: the `seen.escalatedAt === null` guard exists
// so the newest escalated pass wins and an older one can't overwrite it with
// a stale resolution.
const escalatedTwice = foldToQuotes([
    { id: 'p3', quoteId: 'q11', outcome: 'escalated', totalNetBefore: 100, createdAt: '2026-09-05T10:00:00.000+00:00', resolvedAt: '2026-09-05T14:00:00.000+00:00' },
    { id: 'p2', quoteId: 'q11', outcome: 'offered', totalNetBefore: 100, totalNetAfter: 90, createdAt: '2026-09-03T10:00:00.000+00:00' },
    { id: 'p1', quoteId: 'q11', outcome: 'escalated', totalNetBefore: 100, createdAt: '2026-09-01T10:00:00.000+00:00', resolvedAt: '2026-09-01T14:00:00.000+00:00' },
]);

assert.equal(escalatedTwice[0].escalatedAt, '2026-09-05T10:00:00.000+00:00', 'The newer escalation must win, not the older.');
assert.equal(escalatedTwice[0].resolvedAt, '2026-09-05T14:00:00.000+00:00', 'Its resolvedAt must come from the same pass as escalatedAt.');

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

// #55: a merchant's note carries createdById alone. It is not the agent's, and
// it is not the customer's either — the page used to head it "From customer".
const attributed = conversation([
    { id: 'm1', comment: 'Can you do 12%?', customerId: 'c1', createdAt: '2026-09-08T09:00:00+00:00' },
    { id: 'm2', comment: 'We can bring this down by 5%.', createdAt: '2026-09-08T09:05:00+00:00' },
    { id: 'm3', comment: 'Margin is thin here.', createdById: 'u1', createdAt: '2026-09-08T09:10:00+00:00' },
    { id: 'm4', comment: 'Still too much.', employeeId: 'e1', createdAt: '2026-09-08T09:15:00+00:00' },
]);

assert.deepEqual(attributed.map((m) => m.fromAgent), [false, true, false, false]);
assert.deepEqual(attributed.map((m) => m.fromMerchant), [false, false, true, false]);

assert.equal(formatPercent(7.9495), '7.9%');
assert.equal(formatPercent(null), '–');
assert.equal(formatDuration(0), '0 ms');
assert.equal(formatDuration(999), '999 ms');
assert.equal(formatDuration(22556), '22.6 s');
assert.equal(formatDuration(null), '–');

// ------------------------------------------------------------- pass notes

// A pass that escalated explains itself in a sentence carrying its own
// numbers — see escalationExplanation(). Repeating the flags underneath it
// would say the same thing again in weaker words.
assert.deepEqual(
    passNotes(vm, {
        outcome: 'escalated',
        escalationReason: 'verification_failed',
        verified: false,
        violations: ['line 1 is above the cap'],
    }),
    [],
);

// #1021, and the reason this exists: granted, replied, and the transition to
// `replied` never landed. Nothing escalated, so `violations` is the only
// record that a person is needed — and it used to show up nowhere but the
// collapsed technical fold, under a green "Offer sent".
assert.deepEqual(
    passNotes(vm, {
        outcome: 'offered',
        authorized: true,
        verified: true,
        violations: ['Reply posted but admin_resend from reopen did not reach replied.'],
    }),
    [{
        key: 'needsAttention',
        variant: 'critical',
        text: 'needsAttention',
        detail: 'Reply posted but admin_resend from reopen did not reach replied.',
    }],
);

// Why two near-identical passes exist: the earlier one killed the worker.
assert.deepEqual(passNotes(vm, { outcome: 'offered', attempt: 2 }).map((note) => note.text), ['2 attemptRetried']);

// A pass with nothing to answer says so, rather than showing one bare header.
assert.deepEqual(passNotes(vm, { outcome: 'nothing_to_do', attempt: 0 }).map((note) => note.key), ['nothingToDo']);
assert.deepEqual(passNotes(vm, { outcome: 'acknowledged', attempt: 0 }).map((note) => note.key), ['acknowledged']);
assert.deepEqual(passNotes(vm, { outcome: 'handed_over', attempt: 0 }).map((note) => note.key), ['handedOver']);

// A pass that did its job has nothing to add.
assert.deepEqual(passNotes(vm, { outcome: 'offered', authorized: true, verified: true, violations: [] }), []);

// ----------------------------------------------------------------- writes

// The write names OfferApplier records, in the order it performed them.
assert.deepEqual(writeLabels(vm, ['claim', 'updateQuote', 'recalculate']), ['claim', 'updateQuote', 'recalculate']);
assert.deepEqual(writeLabels(vm, ['brandNewWrite']), ['brandNewWrite'], 'An unmapped write is shown, not dropped.');
assert.deepEqual(writeLabels(vm, null), [], 'A pass that wrote nothing has no changes row.');

// --------------------------------------------------------------- outcomes

assert.equal(terminalExplanation(vm, 'accepted'), 'accepted');
assert.equal(terminalExplanation(vm, null), null);
assert.equal(
    terminalExplanation({ $tc: (key) => key }, 'accepted'),
    null,
    'A state with no sentence of its own gets none, rather than a snippet path.',
);

// ------------------------------------------------------------------ stream

const stream = mergeStream(
    [
        { id: 'c1', text: 'Can you do 12%?', fromAgent: false, createdAt: '2026-09-08T09:00:00+00:00' },
        { id: 'c2', text: 'We can bring this down by 5%.', fromAgent: true, createdAt: '2026-09-08T09:05:00+00:00' },
    ],
    [
        { id: 'r1', outcomeVariant: 'positive', raw: { createdAt: '2026-09-08T09:00:00+00:00' } },
        { id: 'r2', outcomeVariant: 'critical', raw: { createdAt: '2026-09-08T09:20:00+00:00' } },
    ],
    { state: 'accepted', at: '2026-09-08T10:00:00+00:00' },
);

// The buyer's comment and the pass it triggered share an instant, and the
// comment came first: it is what the pass is answering.
assert.deepEqual(stream.map((entry) => entry.key), ['c1', 'r1', 'r2', 'outcome']);
assert.deepEqual(stream.map((entry) => entry.kind), ['message', 'pass', 'pass', 'outcome']);
assert.deepEqual(stream.map((entry) => entry.variant), ['neutral', 'positive', 'critical', 'positive']);

// The agent's quote comment is not an entry of its own: that sentence is the
// pass's `replyToBuyer` and renders inside the pass that wrote it.
assert.ok(!stream.some((entry) => entry.key === 'c2'), 'The agent is not quoted twice.');

// A quote still in flight has no closing entry.
assert.deepEqual(mergeStream([], [], null), []);

// No readable quote comments -- Commercial absent, or a role without
// `quote_comment:read` -- falls back to what the passes recorded, so a
// `nothing_to_do` row still says WHICH question the agent passed over (#177).
const fallback = mergeStream(
    [],
    [{
        id: 'r1',
        outcomeVariant: 'neutral',
        raw: { createdAt: '2026-09-18T09:58:51+00:00', buyerAsk: 'Nice, thanks!' },
    }],
    null,
);
assert.deepEqual(fallback.map((entry) => entry.kind), ['message', 'pass']);
assert.equal(fallback[0].message.text, 'Nice, thanks!');
assert.equal(fallback[0].message.fromAgent, false, 'A recorded ask is the buyer speaking.');

// The quote wins whenever it can be read: merging both would print the same
// ask twice, once off the quote and once off the record that consumed it.
const both = mergeStream(
    [{ id: 'c1', text: 'Nice, thanks!', fromAgent: false, createdAt: '2026-09-18T09:58:40+00:00' }],
    [{
        id: 'r1',
        outcomeVariant: 'neutral',
        raw: { createdAt: '2026-09-18T09:58:51+00:00', buyerAsk: 'Nice, thanks!' },
    }],
    null,
);
assert.deepEqual(both.map((entry) => entry.key), ['c1', 'r1']);

// A pass that read no comment at all -- a structured per-line ask -- records
// nothing, and an empty string is not an entry either.
assert.deepEqual(
    mergeStream([], [{ id: 'r1', outcomeVariant: 'neutral', raw: { createdAt: '2026-09-18T09:00:00+00:00' } }], null)
        .map((entry) => entry.kind),
    ['pass'],
);
assert.deepEqual(
    mergeStream([], [{ id: 'r1', outcomeVariant: 'neutral', raw: { createdAt: '2026-09-18T09:00:00+00:00', buyerAsk: '  ' } }], null)
        .map((entry) => entry.kind),
    ['pass'],
);

// A terminal state stamped without a timestamp still closes the rail.
assert.deepEqual(
    mergeStream([], [{ id: 'r1', outcomeVariant: 'neutral', raw: { createdAt: '2026-09-08T09:00:00+00:00' } }], {
        state: 'expired',
        at: null,
    }).map((entry) => entry.kind),
    ['pass', 'outcome'],
);

console.log('decision.ts: ok');

// History is recorded evidence: unknown values must never become invented zeros.
const history = {
    available: true, quotesSeen: 7, quotesConverted: 3, quotesLost: 2,
    offersMade: 5, offersAccepted: 3, lastGrantedDiscountPercent: 0,
    orderCount: 4, lifetimeNet: 1234.56, currencyIso: 'EUR', lastOrderAt: '2026-09-08T10:00:00+00:00',
    rounds: [
        { kind: 'orders', productId: null, result: 'INTERNAL orders\nOrder #42: 12.50 net' },
        { kind: 'product_purchases', productId: 'product-1', result: 'INTERNAL: Refused off-quote product.' },
    ],
};
assert.equal(historySummary(vm, history), [
    'quotesSeen: 7', 'quotesConverted: 3', 'quotesLost: 2', 'offersMade: 5',
    'offersAccepted: 3', 'lastGrantedDiscount: 0.00%', 'orderCount: 4',
    'lifetimeNet: 1234.56 EUR', 'lastOrderAt: 2026-09-08T10:00:00+00:00',
].join('\n'));
assert.equal(historyReads(vm, history),
    '1 round: orders\nINTERNAL orders\nOrder #42: 12.50 net\n\n2 round: product_purchases · productId: product-1\nINTERNAL: Refused off-quote product.');
for (const missing of [null, undefined]) {
    assert.equal(historySummary(vm, missing), '–');
    assert.equal(historyReads(vm, missing), '–');
}
for (const malformed of [[], 'invalid', 42, {}]) {
    assert.equal(historySummary(vm, malformed), 'unknown');
}
assert.equal(historySummary(vm, { available: false, reason: 'Customer is missing.' }), 'unavailable: Customer is missing.');
assert.equal(historySummary(vm, { available: false }), 'unavailable: unknown');
const emptyHistory = {
    ...history, quotesSeen: 0, quotesConverted: 0, quotesLost: 0, offersMade: 0,
    offersAccepted: 0, lastGrantedDiscountPercent: null, orderCount: 0, lifetimeNet: 0,
    lastOrderAt: null, rounds: [],
};
assert.ok(historySummary(vm, emptyHistory).startsWith('none\nquotesSeen: 0'));
assert.ok(historySummary(vm, emptyHistory).includes('lastGrantedDiscount: noRecordedReduction'));
assert.ok(historySummary(vm, emptyHistory).includes('lifetimeNet: 0.00'));
assert.ok(historySummary(vm, emptyHistory).endsWith('lastOrderAt: none'));
assert.equal(historyReads(vm, emptyHistory), 'none');
const malformedSummary = historySummary(vm, {
    ...history, quotesSeen: '7', quotesConverted: -1, quotesLost: 2.5,
    offersMade: Infinity, offersAccepted: NaN, lastGrantedDiscountPercent: null,
    orderCount: null, lifetimeNet: true, lastOrderAt: 'invalid',
});
assert.equal(malformedSummary.split('\n').length, 9);
assert.ok(malformedSummary.split('\n').every((line) => line.endsWith(': unknown') || line.endsWith(': noRecordedReduction')));
assert.equal(historyReads(vm, { rounds: {} }), 'unknown');
assert.equal(historyReads(vm, {}), '–');
assert.equal(historyReads(vm, { rounds: [null, [], { kind: 'future_kind', result: {} }] }),
    '1 round: unknown\nunknown\n\n2 round: unknown\nunknown\n\n3 round: unknown\nunknown');
assert.equal(historyReads(vm, { rounds: [{ kind: 'quote_history', productId: {}, result: '<script>alert(1)</script>' }] }),
    '1 round: quote_history\n<script>alert(1)</script>', 'Stored text stays data for escaped template interpolation.');
const snippets = ['en', 'de'].map((locale) => JSON.parse(readFileSync(new URL(`./snippet/${locale}.json`, import.meta.url)))['merchant-quote-agent']);
assert.deepStrictEqual(Object.keys(snippets[0].history).sort(), Object.keys(snippets[1].history).sort());
for (const snippet of snippets) {
    assert.ok(snippet.detail.agentTitle, "detail.agentTitle snippet missing");
}
for (const snippet of snippets) {
    for (const key of ['customer', 'accountHistory', 'historyReads']) assert.ok(snippet.tech[key]);
    for (const key of ['unknown', 'none', 'unavailable', 'round', 'productId', 'quote_history', 'orders', 'product_purchases', 'lastOrderAt']) assert.ok(snippet.history[key]);
}

assert.ok(!historySummary(vm, { available: true, quotesSeen: 0, orderCount: 0 }).startsWith('none'));
assert.ok(historySummary(vm, { ...history, lastOrderAt: '123' }).endsWith('lastOrderAt: unknown'));
for (const snippet of snippets) {
    const localized = {
        $tc: (key) => snippet.history[key.split('.').pop()],
        $t: (key, values) => snippet.history[key.split('.').pop()].replace('{count}', String(values.count)),
    };
    assert.ok(historyReads(localized, history).startsWith(snippet.history.round.replace('{count}', '1')));
    assert.ok(historyReads(localized, history).includes(snippet.history.product_purchases));
    assert.equal(historyReads(localized, emptyHistory), snippet.history.none);
    assert.equal(historySummary(localized, { available: false, reason: 'Recorded reason' }), `${snippet.history.unavailable}: Recorded reason`);
}
console.log('history.ts: ok');

const mixedCurrencyHistory = historySummary(vm, { ...history, lifetimeNet: null, currencyIso: null, lifetimeNetUnavailableReason: 'Mixed order currencies' });
assert.ok(mixedCurrencyHistory.includes('lifetimeNet: unavailable: Mixed order currencies'));
assert.ok(mixedCurrencyHistory.includes('orderCount: 4'));
assert.ok(mixedCurrencyHistory.includes('lastOrderAt: 2026-09-08'));
assert.ok(!mixedCurrencyHistory.includes('0.00 net'));
assert.ok(historySummary(vm, { ...history, currencyIso: undefined }).includes('lifetimeNet: 1234.56 currencyUnknown'));
for (const invalid of [null, '', [], {}, 'not an ISO']) {
    assert.ok(historySummary(vm, { ...history, currencyIso: invalid }).includes('lifetimeNet: 1234.56 currencyUnknown'));
}
for (const invalid of [Infinity, NaN, -5, {}, '123']) {
    assert.ok(historySummary(vm, { ...history, lifetimeNet: invalid }).includes('lifetimeNet: unknown'));
}
assert.match(snippets[0].history.offersMade, /Authorized proposal passes/);
assert.match(snippets[0].history.offersAccepted, /Accepted quotes with an authorized proposal/);
assert.match(snippets[0].history.lastGrantedDiscount, /Latest recorded pass reduction/);
assert.match(snippets[1].history.lastGrantedDiscount, /Preisreduzierung eines Durchlaufs/);

// Recorded per-pass reductions can be negative when that pass raised the price.
assert.ok(historySummary(vm, { ...history, lastGrantedDiscountPercent: -2 }).includes('lastGrantedDiscount: -2.00%'));
for (const invalid of [Infinity, -Infinity, NaN, '2', {}, undefined]) {
    assert.ok(historySummary(vm, { ...history, lastGrantedDiscountPercent: invalid }).includes('lastGrantedDiscount: unknown'));
}
for (const field of ['quotesSeen', 'quotesConverted', 'quotesLost', 'offersMade', 'offersAccepted', 'orderCount', 'lifetimeNet']) {
    assert.ok(historySummary(vm, { ...history, [field]: -2 }).includes(`${field}: unknown`));
}

// Quote 1012 on the parity shop: round one granted 15%, rounds two and three
// held that same offer. The per-pass reduction is 0 for those, which is what
// the page used to show under a label reading like the quote's discount —
// beside an already-reduced total and a reply quoting 15%. The quote-level
// figure is measured off the earliest pass's opening total, which is the same
// snapshot QuoteBaseline stamps and so the same number the reply text uses.
const held = [
    { discountPercentGranted: 14.9966, totalNetBefore: 2499.9, totalNetAfter: 2125.0, currencyIso: 'EUR' },
    { discountPercentGranted: 0, totalNetBefore: 2125.0, totalNetAfter: 2125.0, currencyIso: 'EUR' },
    { discountPercentGranted: 0, totalNetBefore: 2125.0, totalNetAfter: 2125.0, currencyIso: 'EUR' },
];
const baseline1012 = held[0].totalNetBefore;

assert.deepEqual(
    held.map((round) => formatPercent(quoteDiscountPercent(baseline1012, round.totalNetAfter))),
    ['15.0%', '15.0%', '15.0%'],
);
assert.deepEqual(
    held.map((round) => roundChange(vm, round)),
    [
        '+15.0 roundChange · 2499.90 EUR → 2125.00 EUR',
        'roundUnchanged · 2125.00 EUR → 2125.00 EUR',
        'roundUnchanged · 2125.00 EUR → 2125.00 EUR',
    ],
);

// A pass that moved the total by less than the one decimal the figure shows is
// "unchanged", not "+0.0" — printing a rounded-away delta is the confusion
// this replaced.
assert.match(roundChange(vm, { ...held[1], discountPercentGranted: 0.02 }), /^roundUnchanged/);
assert.match(roundChange(vm, { ...held[1], discountPercentGranted: 0.06 }), /^\+0\.1 roundChange/);

// A pass that raised the price is a real recorded outcome; it reads as a
// negative change rather than as no change.
assert.match(roundChange(vm, { ...held[1], discountPercentGranted: -2.5 }), /^-2\.5 roundChange/);

// Nothing to measure stays absent rather than becoming a confident zero.
assert.equal(quoteDiscountPercent(null, 2125.0), null);
assert.equal(quoteDiscountPercent(0, 2125.0), null);
assert.equal(quoteDiscountPercent(2499.9, null), null);
assert.equal(formatPercent(quoteDiscountPercent(null, 2125.0)), '–');
for (const invalid of [Infinity, NaN, '2499.90', {}, undefined]) {
    assert.equal(quoteDiscountPercent(invalid, 2125.0), null);
}
for (const round of [{ totalNetBefore: null, totalNetAfter: 2125.0 }, { totalNetBefore: 2125.0, totalNetAfter: null }]) {
    assert.equal(roundChange(vm, { ...round, discountPercentGranted: 0 }), 'roundUnchanged');
}
assert.match(snippets[0].detail.roundChange, /this pass/);
assert.ok(snippets[1].detail.roundUnchanged.length > 0);
console.log('decision.ts quote-level discount: ok');
