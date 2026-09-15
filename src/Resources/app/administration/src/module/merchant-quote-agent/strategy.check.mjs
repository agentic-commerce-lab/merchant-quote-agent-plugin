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
import { BUILT_IN_IDS, builtInSnippetKey, isBuiltIn, sortStrategies } from './strategy.ts';

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

console.log('strategy.ts: all checks passed');
