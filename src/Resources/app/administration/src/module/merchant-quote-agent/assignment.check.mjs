import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { ASSIGNMENT_KINDS, ASSIGNMENT_SOURCES, isSavable, splitShares, spreadLabel } from './assignment.ts';

/**
 * The vocabulary is read out of the PHP enum, not out of a copy declared here.
 * A copy would only prove assignment.ts agrees with this file. Admin
 * vocabulary drifting from a backend enum has shipped twice in this project.
 */
function phpEnumValues() {
    const source = readFileSync(
        new URL('../../../../../../Strategy/StrategyAssignmentSource.php', import.meta.url),
        'utf8',
    );

    return [...source.matchAll(/case\s+\w+\s*=\s*'([a-z]+)'\s*;/g)].map((m) => m[1]);
}

const php = phpEnumValues();
assert.ok(php.length === 4, `expected 4 enum cases in the PHP source, found ${php.length}`);
assert.deepEqual([...ASSIGNMENT_SOURCES], php, 'ASSIGNMENT_SOURCES must equal the PHP enum, in order');
assert.deepEqual([...ASSIGNMENT_KINDS], php.filter((v) => v !== 'config'),
    'ASSIGNMENT_KINDS is every source except config, which is the absence of a row');

// isSavable: the admin is the ONLY guard against a rule row with no rule id,
// because PR #185 had to drop rule_id from the table's CHECK constraint.
assert.equal(isSavable({ kind: 'rule', customerId: null, ruleId: null, weight: null, strategyId: 'a', salesChannelId: null }), false);
assert.equal(isSavable({ kind: 'rule', customerId: null, ruleId: 'r', weight: null, strategyId: 'a', salesChannelId: null }), true);
assert.equal(isSavable({ kind: 'pin', customerId: null, ruleId: null, weight: null, strategyId: 'a', salesChannelId: null }), false);
assert.equal(isSavable({ kind: 'pin', customerId: 'c', ruleId: null, weight: null, strategyId: 'a', salesChannelId: null }), true);
assert.equal(isSavable({ kind: 'split', customerId: null, ruleId: null, weight: null, strategyId: 'a', salesChannelId: null }), false);
assert.equal(isSavable({ kind: 'split', customerId: null, ruleId: null, weight: 1, strategyId: 'a', salesChannelId: null }), true);
assert.equal(isSavable({ kind: 'split', customerId: null, ruleId: null, weight: 0, strategyId: 'a', salesChannelId: null }), false,
    'a zero-weight arm can never be chosen, so saving one is a silent no-op');
assert.equal(isSavable({ kind: 'split', customerId: null, ruleId: null, weight: -1, strategyId: 'a', salesChannelId: null }), false,
    'a negative weight drives the arm total toward zero and can lose the assignment entirely');
assert.equal(isSavable({ kind: 'split', customerId: null, ruleId: null, weight: 1, strategyId: null, salesChannelId: null }), false,
    'every row must name a strategy');

// splitShares: percent of the arms' OWN sum, so 1 and 4 read as 20% and 80%.
assert.deepEqual(splitShares([{ weight: 1 }, { weight: 4 }]).map((s) => s.percent), [20, 80]);
assert.deepEqual(splitShares([{ weight: 20 }, { weight: 80 }]).map((s) => s.percent), [20, 80]);
assert.deepEqual(splitShares([{ weight: 1 }, { weight: 1 }, { weight: 1 }]).map((s) => s.percent), [33.3, 33.3, 33.3],
    'thirds are rounded for display and deliberately do not total 100 -- the resolver uses the raw weights');
assert.deepEqual(splitShares([]).map((s) => s.percent), []);
assert.deepEqual(splitShares([{ weight: 0 }, { weight: 0 }]).map((s) => s.percent), [0, 0],
    'a zero total must not divide by zero');
assert.deepEqual(splitShares([{ weight: null }, { weight: 4 }]).map((s) => s.percent), [0, 100]);

// spreadLabel: how a decision row's assignment sources are summarised.
assert.deepEqual(spreadLabel(['rule', 'rule', 'config']), [{ source: 'rule', count: 2 }, { source: 'config', count: 1 }]);
assert.deepEqual(spreadLabel([]), []);
assert.deepEqual(spreadLabel([null, null]), [], 'rows written before the column existed are not a source');
assert.deepEqual(spreadLabel(['config', 'pin', 'config']), [{ source: 'config', count: 2 }, { source: 'pin', count: 1 }],
    'ordered by count descending so the dominant source reads first');

console.log('assignment.check.mjs OK');
