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
import { canaryMismatches, figureCandidates, formatTable, h8StatedFigures, judgeCostUsd, tagPassRates, transcript, unwrapJudgeResult, verdict } from './eval/verdict.mjs';

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
const scenario = (over = {}) => ({ id: 's', tags: ['band'], openingAsk: 'Could you do 5% off?', expect: { firstOutcome: ['offered'], maxEscalations: 1, order: false, judge: [] }, ...over });
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
// An offer written with no quote_before/quote_after trace cannot be checked: fail, never n/a.
assert.match(h3MarginFloor([row({ policy: policy({ minMarginPercent: 15 }), purchasePricesNet: { p1: 8 }, totalNetAfter: 50, linesBefore: null, linesAfter: null })]).reason, /round 1 wrote an offer but its quote_before\/quote_after trace is missing/);
assert.equal(status(h3MarginFloor([row({ policy: policy({ minMarginPercent: 15 }) })], scenario({ lines: [{ quantity: 10, purchasePriceRatio: 0.8 }] }))), 'fail'); // the scenario set a purchase price that never arrived
assert.equal(status(h3MarginFloor([row({ policy: policy({ minMarginPercent: 10 }) })], scenario())), 'n/a'); // a shop-wide floor on a product without a purchase price: no floor applies

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
// A quote-wide discount is a negative line; the positive line keeps its price.
const quoteWide = (after) => [line(), line({ lineItemId: 'd', productId: null, quantity: 1, unitPriceNet: after - 100, totalNet: after - 100 })];
const rounded = (mode, step, after, gross, over = {}) => [row({ policy: policy({ roundingMode: mode, roundingStep: step }), totalNetAfter: after, totalGrossAfter: gross, linesAfter: quoteWide(after), ...over })];
assert.equal(status(h9Rounding(rounded('discount_percent', 1, 88, 104.72))), 'pass');
assert.equal(status(h9Rounding(rounded('discount_percent', 1, 87.5, 104.13))), 'fail');
assert.equal(status(h9Rounding(rounded('quote_total', 5, 95.8, 115))), 'pass');
assert.equal(status(h9Rounding(rounded('quote_total', 5, 95.8, 113.05))), 'fail');
assert.equal(status(h9Rounding(rounded('quote_total', 5, 85, 101.15, { buyerAsk: 'Can you get to 15.0% off?' }))), 'pass'); // the buyer's own figure is left unrounded (QuoteTotalRounding)
assert.equal(status(h9Rounding([row()])), 'n/a');
// The writes DiscountRounding deliberately leaves unrounded (a 0.5 step, as a shop may run):
assert.equal(status(h9Rounding(rounded('discount_percent', 0.5, 91.2, 108.53, { buyerAsk: 'Can you get to 8.8% off?' }))), 'pass'); // the buyer's own figure
assert.equal(status(h9Rounding(rounded('discount_percent', 0.5, 91.2, 108.53, { buyerAsk: 'Can you get to 8,8 % off?' }))), 'pass'); // a decimal comma
assert.equal(status(h9Rounding(rounded('discount_percent', 0.5, 91.2, 108.53))), 'fail'); // asked 5%, wrote 8.8%: not on the step, not theirs
assert.equal(status(h9Rounding(rounded('discount_percent', 0.5, 99.7, 118.64))), 'pass'); // 0.3% would round to zero
const held = [
    row({ round: 1, policy: policy({ roundingMode: 'discount_percent', roundingStep: 0.5 }), totalNetAfter: 87.8, linesAfter: quoteWide(87.8), buyerAsk: 'Could you do 12.2% off?' }),
    row({ round: 2, policy: policy({ roundingMode: 'discount_percent', roundingStep: 0.5 }), totalNetBefore: 87.8, totalNetAfter: 87.7, linesBefore: quoteWide(87.8), linesAfter: quoteWide(87.7), buyerAsk: 'A little more?' }),
];
assert.equal(status(h9Rounding(held)), 'pass');
// A buyer's figure given as a unit price, as an exactly-at-the-ceiling run showed: 735.59 gross is 15% off
// 865.40 gross; the agent wrote 14.99% (read back as 14.9898% through the cent), which PHP left unrounded.
const ceilingLine = (unitPriceNet) => line({ quantity: 1, unitPriceNet, totalNet: unitPriceNet, netRatio: 0.8403362606886989 });
const ceiling = (ask) => [row({ policy: policy({ roundingMode: 'discount_percent', roundingStep: 0.5 }), totalNetBefore: 727.23, totalNetAfter: 618.22, totalGrossBefore: 865.4, totalGrossAfter: 735.68, buyerAsk: ask, linesBefore: [ceilingLine(727.23)], linesAfter: [ceilingLine(727.23), line({ lineItemId: 'd', productId: null, quantity: 1, unitPriceNet: -109.01, totalNet: -109.01 })] })];
assert.equal(status(h9Rounding(ceiling('735.59 including tax and we have a deal.'))), 'pass');
assert.equal(status(h9Rounding(ceiling('700.00 including tax and we have a deal.'))), 'fail'); // a price that is not what was written // 12.3% rounds to 12.0%, below the 12.2% already held
assert.equal(status(h9Rounding(rounded('discount_percent', 0.5, 91.3, 108.65, { linesAfter: [line({ unitPriceNet: 9.13, totalNet: 91.3 })] }))), 'pass'); // a per-line answer is never rounded
// No round-1 baseline: the discount is NaN, which must fail rather than slip past the step test.
const noBaseline = { policy: policy({ roundingMode: 'discount_percent', roundingStep: 1 }) };
assert.equal(status(h9Rounding([row({ ...noBaseline, totalNetBefore: null, totalNetAfter: null }), row({ ...noBaseline, round: 2, totalNetBefore: 100, totalNetAfter: 87.5 })])), 'fail');

// grouping sorts rounds and keys by rep
const grouped = groupNegotiations([row({ round: 2 }), row({ round: 1 }), row({ rep: 2 })]);
assert.equal(grouped.length, 2);
assert.deepEqual(grouped[0].rows.map((r) => r.round), [1, 2]);

// H8 -- figures the reply states must match what the quote carries
const figure = (value, decimals = 2, kind = 'money') => ({ kind, value, decimals, quote: String(value) });
const judged = (figures, round = 1) => ({ rounds: [{ round, statedFigures: figures }], rubric: [] });
assert.equal(status(h8StatedFigures([row()], judged([figure(113.05)]))), 'pass'); // gross total
assert.equal(status(h8StatedFigures([row()], judged([figure(95)]))), 'pass'); // net total
assert.equal(status(h8StatedFigures([row()], judged([figure(9.5)]))), 'pass'); // unit price net
assert.equal(status(h8StatedFigures([row()], judged([figure(5, 0, 'percent')]))), 'pass');
assert.equal(status(h8StatedFigures([row()], judged([figure(1299)]))), 'fail');
// precision-aware: "7%" against 6.97 matches, "7.50%" against 7.40 does not
const at = (after) => [row({ totalNetAfter: after })];
assert.equal(status(h8StatedFigures(at(93.03), judged([figure(7, 0, 'percent')]))), 'pass');
assert.equal(status(h8StatedFigures(at(92.6), judged([figure(7.5, 2, 'percent')]))), 'fail');
assert.equal(status(h8StatedFigures([row()], judged([]))), 'n/a');
assert.equal(status(h8StatedFigures([row()], null)), 'judge_error');
assert.equal(status(h8StatedFigures([row()], judged([figure(95)], 2))), 'judge_error'); // a round the negotiation lacks is the judge's error, not the agent's
assert.ok(figureCandidates([row()], row()).money.includes(100), 'the original total is a legitimate "down from" figure');
// a saving and a per-pass delta are figures the quote carries; a made-up saving is not
assert.equal(status(h8StatedFigures([row()], judged([figure(5)]))), 'pass'); // "you save 5.00" on 100 -> 95
assert.equal(status(h8StatedFigures([row()], judged([figure(5.95)]))), 'pass'); // the same saving, grossed
assert.equal(status(h8StatedFigures([row()], judged([figure(7)]))), 'fail');
const secondPass = [row({ round: 1, totalNetAfter: 90, totalGrossAfter: 107.1 }), row({ round: 2, totalNetBefore: 90, totalNetAfter: 85.5, totalGrossBefore: 107.1, totalGrossAfter: 101.745 })];
assert.equal(status(h8StatedFigures(secondPass, judged([figure(5, 0, 'percent')], 2))), 'pass'); // "another 5%": 90 -> 85.5 this pass, 14.5% overall
assert.deepEqual(figureCandidates([row({ totalNetBefore: null })], row({ totalNetBefore: null })).percent, [], 'no round-1 baseline: no percent candidate, never NaN');

// Review Focus 4 -- a silent pass
const silent = transcript(scenario(), { rows: [row({ replyToBuyer: null })] });
assert.match(silent, /AGENT: \(no reply\)/);
assert.match(transcript(scenario({ expect: { judge: ['No stand for free.'] } }), { rows: [row()] }), /J5\.1: No stand for free\./);

// unwrap -- the claude -p JSON result
assert.deepEqual(unwrapJudgeResult(JSON.stringify({ type: 'result', subtype: 'success', is_error: false, structured_output: { rounds: [], rubric: [] }, total_cost_usd: 0.01 })), { judgment: { rounds: [], rubric: [] }, costUsd: 0.01 });
assert.ok(unwrapJudgeResult('not json').error);
assert.ok(unwrapJudgeResult(JSON.stringify({ subtype: 'error_max_budget_usd', is_error: true })).error);
assert.ok(unwrapJudgeResult(JSON.stringify({ subtype: 'success', is_error: false, result: 'text only' })).error);

// canary
const graded = { rounds: [{ round: 1, statedFigures: [figure(1140)] }], rubric: [{ id: 'J2', verdict: 'pass', reason: '' }] };
assert.deepEqual(canaryMismatches(graded, { rubric: { J2: 'pass' }, figures: [{ round: 1, value: 1140 }] }), []);
assert.equal(canaryMismatches(graded, { rubric: { J2: 'fail' }, figures: [] }).length, 1);
assert.equal(canaryMismatches(graded, { rubric: {}, figures: [{ round: 1, value: 1299 }] }).length, 1);

// verdict -- 3 reps: hard 3/3, rubric 2/3, judge errors are their own category
const rubric = (verdicts) => ({ rounds: [{ round: 1, statedFigures: [] }], rubric: ['J1', 'J2', 'J3', 'J4'].map((id, i) => ({ id, verdict: verdicts[i] ?? 'pass', reason: '' })) });
const reps3 = [1, 2, 3].map((rep) => row({ rep }));
const run = (rows, judgments) => verdict({ scenarios: [scenario()], reps: 3, rows, judgments: new Map(judgments) });
const allPass = run(reps3, [[`s#1`, rubric([])], [`s#2`, rubric([])], [`s#3`, rubric([])]]);
assert.equal(allPass.exitCode, 0);
assert.equal(allPass.scenarios[0].checks.H2.result, 'pass');
assert.equal(allPass.scenarios[0].checks.H2.passes, 3);

const oneHardFail = run([row({ rep: 1 }), row({ rep: 2, totalNetAfter: 80 }), row({ rep: 3 })], [[`s#1`, rubric([])], [`s#2`, rubric([])], [`s#3`, rubric([])]]);
assert.equal(oneHardFail.exitCode, 1);
assert.equal(oneHardFail.scenarios[0].checks.H2.result, 'fail');

const twoOfThree = run(reps3, [[`s#1`, rubric([])], [`s#2`, rubric(['pass', 'fail'])], [`s#3`, rubric([])]]);
assert.equal(twoOfThree.scenarios[0].checks.J2.result, 'pass');
const oneOfThree = run(reps3, [[`s#1`, rubric(['pass', 'fail'])], [`s#2`, rubric(['pass', 'fail'])], [`s#3`, rubric([])]]);
assert.equal(oneOfThree.scenarios[0].checks.J2.result, 'fail');
assert.equal(oneOfThree.exitCode, 1);

const judgeDown = run(reps3, [[`s#1`, rubric([])]]); // two judgments missing
assert.equal(judgeDown.scenarios[0].checks.J1.result, 'err');
assert.equal(judgeDown.exitCode, 2);

// one rep's judgment omits J3: the item still passes 2/3, but the judge error makes the run exit 2, never 0
const noJ3 = { ...rubric([]), rubric: rubric([]).rubric.filter((item) => item.id !== 'J3') };
const itemMissing = run(reps3, [[`s#1`, noJ3], [`s#2`, rubric([])], [`s#3`, rubric([])]]);
assert.equal(itemMissing.scenarios[0].checks.J3.result, 'pass');
assert.equal(itemMissing.scenarios[0].status, 'err');
assert.equal(itemMissing.exitCode, 2);

// Review Focus 1 -- a rep with no JSONL line at all fails H7, never shrinks the denominator
const missingRep = run([row({ rep: 1 }), row({ rep: 2 })], [[`s#1`, rubric([])], [`s#2`, rubric([])]]);
assert.equal(missingRep.scenarios[0].checks.H7.result, 'fail');
assert.match(missingRep.scenarios[0].checks.H7.reps[2].reason, /no JSONL line/);
assert.equal(missingRep.exitCode, 1);

// tags -- pass rate per tag over scenario statuses; an err is not a pass
assert.deepEqual(tagPassRates([
    { tags: ['floor', 'band'], status: 'pass' },
    { tags: ['band'], status: 'fail' },
    { tags: ['band'], status: 'err' },
]), { band: { scenarios: 3, passing: 1, passRate: 0.333 }, floor: { scenarios: 1, passing: 1, passRate: 1 } });
assert.deepEqual(allPass.tags, { band: { scenarios: 1, passing: 1, passRate: 1 } });
assert.deepEqual(allPass.scenarios[0].tags, ['band']);

// usage -- tokens and latency summed over reps and rounds, judge cost from the raw results
const usageRows = [
    row({ rep: 1, round: 1, promptTokens: 1000, completionTokens: 100, buyerLatencyMs: 10_000, durationMs: 8000 }),
    row({ rep: 1, round: 2, promptTokens: 2000, completionTokens: 200, buyerLatencyMs: 20_000, durationMs: 9000 }),
    row({ rep: 2, round: 1, promptTokens: null, completionTokens: null, buyerLatencyMs: null, durationMs: null }), // a pass with no model call
    { runId: 'run', scenarioId: 's', rep: 3, cellFailure: true, failureClass: 'PassTimeout', failureMessage: 'x' },
    row({ scenarioId: 'other', promptTokens: 5, completionTokens: 1, buyerLatencyMs: 40_000 }),
];
const used = verdict({ scenarios: [scenario(), scenario({ id: 'other' })], reps: 3, rows: usageRows, judgments: new Map(), judgeCosts: new Map([['s#1', 0.01], ['s#2', 0.02], ['other#1', 0.5]]) }).usage;
assert.deepEqual(used.scenarios.s, {
    passes: 3, promptTokens: 3000, completionTokens: 300,
    buyerLatency: { count: 2, medianMs: 15_000, maxMs: 20_000 }, passDuration: { count: 2, medianMs: 8500, maxMs: 9000 }, judgeCostUsd: 0.03,
});
assert.equal(used.total.passes, 4, 'a failure row is no pass');
assert.equal(used.total.promptTokens, 3005);
assert.deepEqual(used.total.buyerLatency, { count: 3, medianMs: 20_000, maxMs: 40_000 });
assert.equal(used.total.judgeCostUsd, 0.53);
assert.equal(verdict({ scenarios: [scenario()], reps: 1, rows: [row()], judgments: new Map() }).usage.total.judgeCostUsd, null, 'no reported cost: null, never 0');
assert.equal(judgeCostUsd(JSON.stringify({ subtype: 'error_max_budget_usd', is_error: true, total_cost_usd: 0.5 })), 0.5, 'a failed call cost money too');
assert.equal(judgeCostUsd(JSON.stringify({ subtype: 'success' })), null);
assert.equal(judgeCostUsd('not json'), null);
assert.match(formatTable(verdict({ scenarios: [scenario()], reps: 3, rows: usageRows, judgments: new Map(), judgeCosts: new Map([['s#1', 0.01]]) })), /usage: 3 passes, 3000 prompt \+ 300 completion tokens, buyer wait median 15\.0 s, max 20\.0 s, judge \$0\.01/);

console.log('eval-check: ok');
