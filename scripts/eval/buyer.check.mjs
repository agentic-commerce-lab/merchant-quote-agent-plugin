/**
 * Self-check for the UCP eval buyer (spec 2026-09-28-claude-code-evals-design,
 * "Testing"). No network: every HTTP call below goes to a fake fetch.
 *
 *     node scripts/eval/buyer.check.mjs
 */
import assert from 'node:assert/strict';
import { buyerMove, loadScenarioDir, render, validateScenario } from './scenarios.mjs';

const base = (over = {}) => ({
    id: 's', description: 'd', lines: [{ productRef: 'any-purchasable', quantity: 10 }], openingAsk: 'Could you do 5% off?',
    persona: 'scripted:moderate', maxRounds: 3, expect: { firstOutcome: ['offered'] }, ...over,
});
const refuses = (over, pattern) => assert.throws(() => validateScenario(base(over)), pattern);

// validation
validateScenario(base());
refuses({ expectedBand: 'auto' }, /expect\.firstOutcome/);
refuses({ expect: { firstOutcome: ['replied'] } }, /replied.*NegotiationOutcome/);
refuses({ expect: { firstOutcome: [] } }, /at least one/);
refuses({ policy: { maxDiscount: 10 } }, /policy\.maxDiscount\b/);
refuses({ policy: { roundingMode: 'nearest' } }, /roundingMode/);
refuses({ buyer: { patience: 3 } }, /buyer\.patience/);
refuses({ policy: { minMarginPercent: 15 } }, /purchasePriceRatio/);
refuses({ policy: { minMarginPercent: 20 }, lines: [{ productRef: 'any-purchasable', quantity: 1, purchasePriceRatio: 0.9 }] }, /at or above/);
refuses({ continueAfterEscalation: true }, /counters/);
assert.equal(loadScenarioDir('tests/Bench/scenarios').length >= 10, true, 'the shipped scenarios validate');

// placeholders
assert.equal(render('Can you get to {unit*0.89} a unit?', 80), 'Can you get to 71.20 a unit?');
assert.equal(render('{unit*0.9} or {unit*0.8}', 50), '45.00 or 40.00');
assert.equal(render('Could you do 5% off?', 80), 'Could you do 5% off?');

// the scripted buyer -- same rules as tests/Bench/ScriptedBuyer.php
assert.deepEqual(buyerMove(base(), { openingNet: 1000, currentNet: 890, round: 1 }), { kind: 'accept' }); // 11% >= 10% default
assert.deepEqual(buyerMove(base(), { openingNet: 1000, currentNet: 960, round: 1 }), { kind: 'counter', comment: 'That still leaves us short. Can you get to 7.0% off?' });
// 4.5% realized asks 7.25%: PHP's sprintf('%.1f') rounds that exact tie to even, toFixed would print 7.3
assert.deepEqual(buyerMove(base(), { openingNet: 1000, currentNet: 955, round: 1 }), { kind: 'counter', comment: 'That still leaves us short. Can you get to 7.2% off?' });
assert.deepEqual(buyerMove(base({ buyer: { targetDiscountPercent: 5 } }), { openingNet: 1000, currentNet: 950, round: 1 }), { kind: 'accept' });
const retreat = base({ buyer: { targetDiscountPercent: 50 }, counters: ['8% would work.'] });
assert.deepEqual(buyerMove(retreat, { openingNet: 1000, currentNet: 880, round: 1 }), { kind: 'counter', comment: '8% would work.' });
assert.deepEqual(buyerMove(retreat, { openingNet: 1000, currentNet: 880, round: 2 }), { kind: 'walk' });

console.log('buyer: ok');
