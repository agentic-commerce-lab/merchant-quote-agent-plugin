/**
 * Self-check for improvement.ts. No test runner: the project has no JS
 * toolchain, and these helpers do not justify adding one.
 *
 *     node --experimental-strip-types src/Resources/app/administration/src/module/merchant-quote-agent/improvement.check.mjs
 *
 * CADENCE_DAYS is asserted against `src/Improvement/ImprovementCadence.php`
 * itself -- read here by regex, not against a third literal copy declared in
 * this file. A drifted mapping is not cosmetic: it misreports when the next
 * run is due.
 */

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    CADENCE_DAYS,
    MIN_SHOWABLE_SAMPLE,
    cadenceDays,
    escalationDelta,
    isBetter,
    isShowableAsBetter,
    nextRunDueAt,
    runsEmptyState,
    unshowableReason,
} from './improvement.ts';

const phpSource = readFileSync(
    new URL('../../../../../../Improvement/ImprovementCadence.php', import.meta.url),
    'utf8',
);

/** `case NAME = 'raw';` -> { NAME: 'raw' } in declaration order. */
function extractCases(source) {
    const cases = {};

    for (const match of source.matchAll(/case (\w+) = '([^']+)';/g)) {
        cases[match[1]] = match[2];
    }

    assert.ok(Object.keys(cases).length > 0, 'Could not find any enum cases in ImprovementCadence.php');

    return cases;
}

/** `self::NAME => N,` inside days() -> { NAME: N }. */
function extractDays(source) {
    const days = {};

    for (const match of source.matchAll(/self::(\w+) => (\d+),/g)) {
        days[match[1]] = Number(match[2]);
    }

    assert.ok(Object.keys(days).length > 0, 'Could not find the days() match arms in ImprovementCadence.php');

    return days;
}

const cases = extractCases(phpSource);
const days = extractDays(phpSource);
const expectedCadenceDays = Object.fromEntries(
    Object.entries(cases).map(([name, raw]) => [raw, days[name]]),
);

// CADENCE_DAYS MUST match ImprovementCadence.php's raw value -> days() mapping.
// A drift here shows up as "next run due" reporting the wrong date.
assert.deepEqual(CADENCE_DAYS, expectedCadenceDays);

assert.equal(cadenceDays('daily'), 1);
assert.equal(cadenceDays('every3days'), 3);
assert.equal(cadenceDays('weekly'), 7);
assert.equal(cadenceDays(null), 1, 'unreadable cadence falls back to daily, like ImprovementCadence::fromRaw()');
assert.equal(cadenceDays(undefined), 1);
assert.equal(cadenceDays('nonsense'), 1);

assert.equal(MIN_SHOWABLE_SAMPLE, 10);

// --- escalationDelta -------------------------------------------------------

/** Percentage-point deltas travel through floating-point multiplication. */
function assertCloseTo(actual, expected, message) {
    assert.ok(Math.abs(actual - expected) < 1e-9, `${message}: expected ${expected}, got ${actual}`);
}

assertCloseTo(
    escalationDelta(
        { sampled: 20, escalationRate: 0.3, meanGrantedPercent: null },
        { sampled: 20, escalationRate: 0.3, meanGrantedPercent: null },
    ),
    0,
    'equal rates',
);

assertCloseTo(
    escalationDelta(
        { sampled: 20, escalationRate: 0.3, meanGrantedPercent: null },
        { sampled: 20, escalationRate: 0.2, meanGrantedPercent: null },
    ),
    -10,
    'candidate escalates ten points less than control',
);

assertCloseTo(
    escalationDelta(
        { sampled: 20, escalationRate: 0.2, meanGrantedPercent: null },
        { sampled: 20, escalationRate: 0.35, meanGrantedPercent: null },
    ),
    15,
    'candidate escalates fifteen points more than control',
);

// --- isShowableAsBetter / unshowableReason: the two refusals ---------------

function evaluation({ controlSampled = 20, candidateSampled = 20, sampleSize = 20, diverged = false } = {}) {
    return {
        control: { sampled: controlSampled, escalationRate: 0.3, meanGrantedPercent: null },
        candidate: { sampled: candidateSampled, escalationRate: 0.2, meanGrantedPercent: null },
        sampleSize,
        skipped: 0,
        diverged,
    };
}

// A healthy evaluation is showable.
assert.equal(isShowableAsBetter(evaluation()), true);
assert.equal(unshowableReason(evaluation()), null);

// Refusal 1: the control arm diverged, however large the sample.
assert.equal(isShowableAsBetter(evaluation({ diverged: true })), false);
assert.equal(unshowableReason(evaluation({ diverged: true })), 'diverged');

// Refusal 2: the sample is below MIN_SHOWABLE_SAMPLE -- via the overall size,
// or via either arm's own count of clean replays after failures dropped rows.
assert.equal(isShowableAsBetter(evaluation({ sampleSize: 9 })), false);
assert.equal(unshowableReason(evaluation({ sampleSize: 9 })), 'smallSample');
assert.equal(isShowableAsBetter(evaluation({ controlSampled: 3 })), false);
assert.equal(isShowableAsBetter(evaluation({ candidateSampled: 3 })), false);

// Exactly at the floor is showable; one below is not.
assert.equal(isShowableAsBetter(evaluation({ sampleSize: 10, controlSampled: 10, candidateSampled: 10 })), true);
assert.equal(isShowableAsBetter(evaluation({ sampleSize: 10, controlSampled: 10, candidateSampled: 9 })), false);

// Diverged wins over a healthy sample when asked why, not "smallSample".
assert.equal(unshowableReason(evaluation({ diverged: true, sampleSize: 3 })), 'diverged');

// --- isBetter ----------------------------------------------------------

// Showable and favors the candidate (fewer escalations).
assert.equal(isBetter(evaluation()), true);

// Showable but does NOT favor the candidate: no badge even though it could be shown.
const worse = evaluation();
worse.candidate.escalationRate = 0.4;
assert.equal(isBetter(worse), false);

// Would favor the candidate, but not showable: no badge either.
assert.equal(isBetter(evaluation({ diverged: true })), false);
assert.equal(isBetter(evaluation({ sampleSize: 5 })), false);

// --- nextRunDueAt / runsEmptyState -----------------------------------------

assert.equal(nextRunDueAt(null, 'daily'), null);
assert.deepEqual(nextRunDueAt('2026-09-01T00:00:00.000Z', 'daily'), new Date('2026-09-02T00:00:00.000Z'));
assert.deepEqual(nextRunDueAt('2026-09-01T00:00:00.000Z', 'weekly'), new Date('2026-09-08T00:00:00.000Z'));

const now = new Date('2026-09-10T00:00:00.000Z');

// Disabled wins regardless of what the runs say.
assert.equal(runsEmptyState(false, [], 'daily', now), 'disabled');
assert.equal(
    runsEmptyState(false, [{ status: 'completed', finishedAt: now.toISOString() }], 'daily', now),
    'disabled',
);

// Enabled, never ticked: no worker, not "quiet".
assert.equal(runsEmptyState(true, [], 'daily', now), 'noWorker');

// Enabled, newest run found nothing in the window.
assert.equal(
    runsEmptyState(true, [{ status: 'no_data', finishedAt: now.toISOString() }], 'daily', now),
    'noData',
);

// Enabled, last completed run was recent and the weekly cadence has not elapsed.
assert.equal(
    runsEmptyState(
        true,
        [{ status: 'completed', finishedAt: '2026-09-09T00:00:00.000Z' }],
        'weekly',
        now,
    ),
    'notDueYet',
);

// Enabled, a completed run exists and the cadence HAS elapsed: nothing left to explain away.
assert.equal(
    runsEmptyState(
        true,
        [{ status: 'completed', finishedAt: '2026-08-01T00:00:00.000Z' }],
        'daily',
        now,
    ),
    null,
);

// A running or failed newest row is informative on its own; no extra sentence.
assert.equal(runsEmptyState(true, [{ status: 'running', finishedAt: null }], 'daily', now), null);
assert.equal(runsEmptyState(true, [{ status: 'failed', finishedAt: '2026-09-09T00:00:00.000Z' }], 'daily', now), null);

console.log('improvement.ts: all checks passed');
