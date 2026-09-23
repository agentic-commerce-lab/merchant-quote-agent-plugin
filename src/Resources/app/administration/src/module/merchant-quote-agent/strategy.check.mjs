/**
 * Self-check for strategy.ts. No test runner: the project has no JS toolchain,
 * and these helpers do not justify adding one.
 *
 *     node --experimental-strip-types src/Resources/app/administration/src/module/merchant-quote-agent/strategy.check.mjs
 *
 * BUILT_IN_IDS is asserted against `src/Strategy/BuiltInStrategies.php` itself
 * -- read here by regex, not against a third literal copy declared in this
 * file. Pinning against a copy declared here would only prove strategy.ts
 * agrees with this file, not with the PHP source of truth: if the PHP
 * constants ever changed, a matching hardcoded copy here would still pass. A
 * drifted id is not cosmetic -- it renders a built-in strategy as a custom
 * one: editable in the UI, then refused by StrategyWriteGuard on save.
 */

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    BUILT_IN_IDS,
    builtInSnippetKey,
    isBuiltIn,
    newStrategySync,
    selectableStrategies,
    sortStrategies,
    VERSIONED_AGGREGATION,
    versionedIds,
} from './strategy.ts';

const phpSource = readFileSync(
    new URL('../../../../../../Strategy/BuiltInStrategies.php', import.meta.url),
    'utf8',
);

/** Extracts `public const NAME = '...';` in declaration order. */
function extractConstant(source, name) {
    const match = source.match(new RegExp(`public const ${name}\\s*=\\s*'([^']+)'`));

    assert.ok(match, `Could not find constant ${name} in BuiltInStrategies.php`);

    return match[1];
}

const MARGIN_DEFENDER = extractConstant(phpSource, 'MARGIN_DEFENDER');
const FAST_CLOSE = extractConstant(phpSource, 'FAST_CLOSE');
const RELATIONSHIP_BUILDER = extractConstant(phpSource, 'RELATIONSHIP_BUILDER');

// The ids MUST match src/Strategy/BuiltInStrategies.php. A drift here shows up
// as a built-in rendering as a custom strategy -- editable in the UI, then
// refused by StrategyWriteGuard on save.
assert.deepEqual(BUILT_IN_IDS, [MARGIN_DEFENDER, FAST_CLOSE, RELATIONSHIP_BUILDER]);

assert.equal(isBuiltIn(FAST_CLOSE), true);
assert.equal(isBuiltIn('0123456789abcdef0123456789abcdef'), false);
assert.equal(isBuiltIn(undefined), false);
assert.equal(isBuiltIn(null), false);

assert.equal(builtInSnippetKey(MARGIN_DEFENDER), 'marginDefender');
assert.equal(builtInSnippetKey(FAST_CLOSE), 'fastClose');
assert.equal(builtInSnippetKey('0123456789abcdef0123456789abcdef'), null);

// Built-ins first in declaration order, then everything else by name.
const sorted = sortStrategies([
    { id: 'ffff56789abcdef0123456789abcdef0', name: 'Zebra' },
    { id: FAST_CLOSE, name: 'Fast close' },
    { id: 'eeee56789abcdef0123456789abcdef0', name: 'Alpha' },
    { id: MARGIN_DEFENDER, name: 'Margin defender' },
]);
assert.deepEqual(
    sorted.map((strategy) => strategy.name),
    ['Margin defender', 'Fast close', 'Alpha', 'Zebra'],
);

assert.deepEqual(sortStrategies([]), []);

// selectableStrategies drops an archived strategy and a versionless one, keeps
// a live versioned one, and keeps the order it was given. One deepEqual proves
// all of it: letting Zebra (archived) or Quiet (no version) through, or
// reordering the survivors, fails here. Zebra IS versioned, so the archived
// exclusion is tested on its own rather than masked by the version one.
const ZEBRA = 'ffff56789abcdef0123456789abcdef0';
const QUIET = 'dddd56789abcdef0123456789abcdef0';
const candidates = sortStrategies([
    { id: FAST_CLOSE, name: 'Fast close' },
    { id: ZEBRA, name: 'Zebra', archivedAt: '2026-01-01T00:00:00.000Z' },
    { id: QUIET, name: 'Quiet' },
    { id: MARGIN_DEFENDER, name: 'Margin defender' },
]);
assert.deepEqual(
    selectableStrategies(candidates, new Set([FAST_CLOSE, MARGIN_DEFENDER, ZEBRA])).map((strategy) => strategy.id),
    [MARGIN_DEFENDER, FAST_CLOSE],
);
assert.deepEqual(selectableStrategies(candidates, new Set()), []);

// versionedIds reads the bucket keys of the named aggregation -- the shape a
// DAL `terms` aggregation returns -- and reads anything else as no versions,
// so a response without the aggregation offers nothing rather than all.
assert.deepEqual(
    [...versionedIds({ [VERSIONED_AGGREGATION]: { buckets: [{ key: FAST_CLOSE, count: 2 }, { key: ZEBRA, count: 1 }] } })],
    [FAST_CLOSE, ZEBRA],
);
assert.deepEqual([...versionedIds({ other: { buckets: [{ key: FAST_CLOSE }] } })], []);
assert.deepEqual([...versionedIds(null)], []);

// newStrategySync writes the strategy and its version 1 in ONE payload, the
// version pointing at the strategy -- two requests are what left a strategy
// with no version when the second one was refused.
const sync = newStrategySync(ZEBRA, QUIET, 'Q4 Firm', 'Hold the price.');
assert.deepEqual(
    Object.values(sync).map((operation) => [operation.entity, operation.action, operation.payload]),
    [
        ['merchant_quote_agent_strategy', 'upsert', [{ id: ZEBRA, name: 'Q4 Firm' }]],
        [
            'merchant_quote_agent_strategy_version',
            'upsert',
            [{ id: QUIET, strategyId: ZEBRA, version: 1, prompt: 'Hold the price.' }],
        ],
    ],
);

console.log('strategy.ts: all checks passed');
