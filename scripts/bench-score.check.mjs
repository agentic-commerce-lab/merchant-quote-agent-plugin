/**
 * Self-check for bench-score.mjs's failure-row handling (Ruling A6: this
 * must be able to fail). Same arrangement as measures.check.mjs and
 * decision.check.mjs -- no test runner, because the project has no JS
 * toolchain.
 *
 *     node --experimental-strip-types scripts/bench-score.check.mjs
 *
 * The regression this guards: `BenchRunTest` now writes a failure row for a
 * cell that threw before any decision row existed (`cellFailure: true`). If
 * the scorer ever folds that row into a measured group -- treating a
 * threw-before-any-row cell as if it had negotiated -- a run with an
 * unreachable model would silently inflate or corrupt that model's numbers
 * instead of showing up as a failed cell.
 */

import assert from 'node:assert/strict';
import { buildGroups, groupKey, partitionRows } from './bench-score.mjs';

const decisionRow = {
    runId: 'run-1',
    scenarioId: 'plain-percentage',
    round: 1,
    quoteId: 'quote-1',
    outcome: 'offered',
    band: 'inside',
    discountPercentGranted: 5,
    totalNetBefore: 100,
    totalNetAfter: 95,
    strategyVersionId: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    model: 'model-a',
    promptTokens: 10,
    completionTokens: 20,
    terminalState: 'accepted',
    createdAt: '2026-09-01T10:00:00.000+00:00',
    resolvedAt: null,
    orderId: 'order-1',
    orderFailure: null,
};

// A model unreachable for its whole cell: no decision row, only the failure
// BenchRunTest::attemptCell now records.
const failureRow = {
    runId: 'run-1',
    scenarioId: 'plain-percentage',
    strategyVersionId: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
    model: 'model-unreachable',
    cellFailure: true,
    failureClass: 'RuntimeException',
    failureMessage: 'Could not reach model-unreachable: 503 Service Unavailable',
};

const rows = [decisionRow, failureRow];

const { failureRows, decisionRows } = partitionRows(rows);

// Counted as a failed cell.
assert.equal(failureRows.length, 1, 'the failure row must be counted as a failed cell');
assert.equal(failureRows[0].model, 'model-unreachable');

// ...and excluded from the measured groups: only the real decision row
// remains, and only its own group exists.
assert.equal(decisionRows.length, 1, 'a failure row must never be folded in as a decision row');

const { groups, attributed } = buildGroups(decisionRows);
assert.equal(attributed.length, 1);
assert.equal(groups.size, 1, 'the failed cell must not appear as a group of its own');
assert.ok(
    !groups.has(groupKey(failureRow)),
    'a failed cell must never be folded into a measure as if it were a negotiation',
);

// eslint-disable-next-line no-console
console.log('bench-score.check.mjs: all assertions passed');
