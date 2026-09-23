/**
 * Self-check for assignment.ts. No test runner: the project has no JS
 * toolchain, and these helpers do not justify adding one.
 *
 *     node --experimental-strip-types src/Resources/app/administration/src/module/merchant-quote-agent/assignment.check.mjs
 *
 * ASSIGNMENT_SOURCES and ASSIGNMENT_KINDS are asserted against
 * `src/Strategy/StrategyAssignmentSource.php` itself -- read here by regex,
 * not against a third literal copy declared in this file. Pinning against a
 * copy declared here would only prove assignment.ts agrees with this file,
 * not with the PHP source of truth: if the PHP enum ever changed, a matching
 * hardcoded copy here would still pass. Admin vocabulary drifting from a
 * backend enum has shipped twice in this project.
 */

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { ASSIGNMENT_KINDS, ASSIGNMENT_SOURCES, isDuplicatePin, isSavable, splitShares, spreadLabel } from './assignment.ts';

/**
 * Extracts the PHP enum's case values, in declaration order, straight from
 * the source file rather than from a second copy declared in this check.
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

// An empty string is not hypothetical: an sw-entity-single-select that has
// been opened and cleared hands back '' rather than null in some Shopware
// builds, which is exactly the path that would create the invisible dead
// binding isSavable exists to prevent.
assert.equal(isSavable({ kind: 'pin', customerId: '', ruleId: null, weight: null, strategyId: 's', salesChannelId: null }), false);
assert.equal(isSavable({ kind: 'rule', customerId: null, ruleId: '', weight: null, strategyId: 's', salesChannelId: null }), false);
assert.equal(isSavable({ kind: 'split', customerId: null, ruleId: null, weight: 1, strategyId: '', salesChannelId: null }), false);

// splitShares: percent of the arms' OWN sum, so 1 and 4 read as 20% and 80%.
assert.deepEqual(splitShares([{ weight: 1 }, { weight: 4 }]).map((s) => s.percent), [20, 80]);
assert.deepEqual(splitShares([{ weight: 20 }, { weight: 80 }]).map((s) => s.percent), [20, 80]);
assert.deepEqual(splitShares([{ weight: 1 }, { weight: 1 }, { weight: 1 }]).map((s) => s.percent), [33.3, 33.3, 33.3],
    'thirds are rounded for display and deliberately do not total 100 -- the resolver uses the raw weights');
assert.deepEqual(splitShares([]).map((s) => s.percent), []);
assert.deepEqual(splitShares([{ weight: 0 }, { weight: 0 }]).map((s) => s.percent), [0, 0],
    'a zero total must not divide by zero');
assert.deepEqual(splitShares([{ weight: null }, { weight: 4 }]).map((s) => s.percent), [0, 100]);
assert.deepEqual(splitShares([{ weight: -5 }, { weight: 5 }]).map((s) => s.percent), [0, 100],
    'a negative weight is floored to zero for display; unfloored it would make the total 0 and divide by zero');

// spreadLabel: how a decision row's assignment sources are summarised.
assert.deepEqual(spreadLabel(['rule', 'rule', 'config']), [{ source: 'rule', count: 2 }, { source: 'config', count: 1 }]);
assert.deepEqual(spreadLabel([]), []);
assert.deepEqual(spreadLabel([null, null]), [], 'rows written before the column existed are not a source');
assert.deepEqual(spreadLabel(['config', 'pin', 'config']), [{ source: 'config', count: 2 }, { source: 'pin', count: 1 }],
    'ordered by count descending so the dominant source reads first');

// A pin row straight from addPin() must not be savable until it names both a
// customer and a strategy -- the grid's Save button is bound to this.
assert.equal(isSavable({ kind: 'pin', customerId: null, ruleId: null, weight: null, strategyId: null, salesChannelId: null }), false);
assert.equal(isSavable({ kind: 'pin', customerId: 'c', ruleId: null, weight: null, strategyId: null, salesChannelId: null }), false);
assert.equal(isSavable({ kind: 'pin', customerId: 'c', ruleId: null, weight: null, strategyId: 's', salesChannelId: null }), true);
assert.equal(isSavable({ kind: 'pin', customerId: 'c', ruleId: null, weight: null, strategyId: 's', salesChannelId: 'ch' }), true,
    'a channel-scoped pin is as valid as a global one');

// isDuplicatePin: the admin-side guard for the half of the uniqueness
// invariant MySQL cannot enforce -- two global pins for the same customer,
// since NULL is distinct from NULL in a unique index.
const globalPin = { kind: 'pin', customerId: 'c1', ruleId: null, weight: null, strategyId: 's1', salesChannelId: null };
const channelPin = { kind: 'pin', customerId: 'c1', ruleId: null, weight: null, strategyId: 's1', salesChannelId: 'ch1' };

assert.equal(isDuplicatePin(globalPin, [globalPin]), false, 'a row is not its own duplicate');
assert.equal(isDuplicatePin({ ...globalPin }, [globalPin]), true, 'a second global pin for the same customer');
assert.equal(isDuplicatePin({ ...globalPin, salesChannelId: '' }, [globalPin]), true, "'' and null are the same scope");
assert.equal(isDuplicatePin(channelPin, [globalPin]), false, 'a channel pin alongside a global one is the point of scoping');
assert.equal(isDuplicatePin({ ...channelPin }, [channelPin]), true, 'two pins on the same channel');
assert.equal(isDuplicatePin({ ...globalPin, customerId: 'c2' }, [globalPin]), false, 'a different customer');
assert.equal(isDuplicatePin({ ...globalPin, kind: 'rule', ruleId: 'r' }, [globalPin]), false, 'only pins collide with pins');

console.log('assignment.check.mjs OK');
