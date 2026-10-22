/**
 * Pure helpers for the nightly self-improvement review page.
 *
 * No JS test runner in this repo: see strategy.ts's own docblock for why
 * these live beside a `node --experimental-strip-types` self-check instead
 * of in a test file.
 */

export interface ArmScoreLike {
    sampled: number;
    escalationRate: number;
    meanGrantedPercent: number | null;
}

/** The `evaluation` JSON StrategyProposalWriter::row() writes, read back from the API. */
export interface EvaluationLike {
    control: ArmScoreLike;
    candidate: ArmScoreLike;
    sampleSize: number;
    skipped: number;
    diverged: boolean;
}

/**
 * Below ten replayed decisions, one decision moves an escalation rate by ten
 * percentage points -- larger than the 15-point divergence threshold the same
 * run already uses to distrust its own control arm (ControlDivergence::MAX_POINTS).
 * A "40% fewer escalations" badge computed on three decisions is a lie a
 * merchant would act on, so nothing below this floor is shown as better or
 * worse than control.
 */
export const MIN_SHOWABLE_SAMPLE = 10;

/** Percentage-point delta, candidate minus control. Negative is fewer escalations. */
export function escalationDelta(control: ArmScoreLike, candidate: ArmScoreLike): number {
    return (candidate.escalationRate - control.escalationRate) * 100;
}

/**
 * The smallest of the three counts an evaluation carries: the run's overall
 * replayed sample, and each arm's own count of clean (non-failed) replays. An
 * arm that lost most of its replays to model failures has a smaller real
 * sample than `sampleSize` alone says, and the smaller number is the honest
 * one to gate on.
 */
function effectiveSample(evaluation: EvaluationLike): number {
    return Math.min(evaluation.sampleSize, evaluation.control.sampled, evaluation.candidate.sampled);
}

/**
 * Whether a delta may be shown as an improvement at all.
 *
 * False when the control arm diverged, and false below a minimum sample: a
 * "40% fewer escalations" badge computed on three decisions is a lie a
 * merchant would act on.
 */
export function isShowableAsBetter(evaluation: EvaluationLike): boolean {
    return !evaluation.diverged && effectiveSample(evaluation) >= MIN_SHOWABLE_SAMPLE;
}

/** Why isShowableAsBetter() refused, for the sentence shown next to a hidden badge. */
export type UnshowableReason = 'diverged' | 'smallSample';

export function unshowableReason(evaluation: EvaluationLike): UnshowableReason | null {
    if (evaluation.diverged) {
        return 'diverged';
    }

    return effectiveSample(evaluation) < MIN_SHOWABLE_SAMPLE ? 'smallSample' : null;
}

/** Showable AND actually favors the candidate -- the only case worth a "better" badge. */
export function isBetter(evaluation: EvaluationLike): boolean {
    return isShowableAsBetter(evaluation) && escalationDelta(evaluation.control, evaluation.candidate) < 0;
}

/**
 * Days per cadence option, duplicated from `ImprovementCadence::days()`. Like
 * strategy.ts's BUILT_IN_IDS, this is frozen by definition rather than fetched,
 * and improvement.check.mjs pins it against that PHP source so a drift here
 * cannot silently misreport when the next run is due.
 */
export const CADENCE_DAYS: Record<string, number> = {
    daily: 1,
    every3days: 3,
    weekly: 7,
};

export function cadenceDays(cadence: string | null | undefined): number {
    return CADENCE_DAYS[cadence ?? ''] ?? CADENCE_DAYS.daily;
}

export interface RunLike {
    status: string;
    finishedAt: string | null;
}

/**
 * The newest completed run's `finishedAt` plus the configured cadence, or
 * null when there has never been a completed run to measure from.
 */
export function nextRunDueAt(
    newestCompletedFinishedAt: string | null,
    cadence: string | null | undefined,
): Date | null {
    if (newestCompletedFinishedAt === null) {
        return null;
    }

    const due = new Date(newestCompletedFinishedAt);
    due.setUTCDate(due.getUTCDate() + cadenceDays(cadence));

    return due;
}

export type RunsEmptyState = 'disabled' | 'noWorker' | 'noData' | 'notDueYet';

/**
 * Why the runs list has nothing informative to show, so the merchant reads
 * one of four different next actions instead of a blank table that could
 * mean anything from "all quiet" to "nothing has ever run".
 *
 * `runs` must be newest first. Returns null once there is something worth
 * rendering as a table instead -- the caller shows that.
 *
 * `enabled` is read at GLOBAL config scope by this page's caller (see
 * index.ts), so it can read false for a shop that only turned the feature on
 * for ONE sales channel -- a channel override does not inherit upward. A run
 * row is proof the feature is on somewhere, so `disabled` is only ever
 * returned alongside an EMPTY `runs`; any row present is read for what it
 * says instead, regardless of what `enabled` claims.
 *
 * `noWorker` and `disabled` are the only two states reachable with an empty
 * `runs`: ImprovementWindow::due() always returns a window immediately when
 * no completed run exists yet, so an enabled feature with zero rows can only
 * mean the scheduled task has never ticked -- no worker, and the
 * Administration was never left open either. `noData` and `notDueYet` both
 * require at least one row and are read from the newest run's own status
 * instead.
 */
export function runsEmptyState(
    enabled: boolean,
    runs: RunLike[],
    cadence: string | null | undefined,
    now: Date,
): RunsEmptyState | null {
    if (runs.length === 0) {
        return enabled ? 'noWorker' : 'disabled';
    }

    if (runs[0].status === 'no_data') {
        return 'noData';
    }

    const newestCompleted = runs.find((run) => run.status === 'completed') ?? null;
    const due = nextRunDueAt(newestCompleted?.finishedAt ?? null, cadence);

    return due !== null && now < due ? 'notDueYet' : null;
}
