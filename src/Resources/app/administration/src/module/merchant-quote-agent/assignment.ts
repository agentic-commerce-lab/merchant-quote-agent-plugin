/**
 * Pure helpers for the strategy assignment tab. No Vue, no repository: the
 * grids are thin, and everything here is checked by assignment.check.mjs.
 *
 * The vocabulary mirrors `MerchantQuoteAgentPlugin\Strategy\
 * StrategyAssignmentSource`. It is duplicated rather than fetched because four
 * frozen words are not worth a network round trip, and assignment.check.mjs
 * pins the duplication against the PHP enum itself.
 */

export const ASSIGNMENT_SOURCES = ['pin', 'rule', 'split', 'config'] as const;

/** Every source except `config`, which is the absence of a row, never a row. */
export const ASSIGNMENT_KINDS = ['pin', 'rule', 'split'] as const;

export interface AssignmentLike {
    kind: string;
    customerId: string | null;
    ruleId: string | null;
    weight: number | null;
    strategyId: string | null;
    salesChannelId: string | null;
}

/**
 * Whether this row is worth writing.
 *
 * This is the only guard against a `kind = 'rule'` row with no `rule_id`. The
 * table's CHECK constraint cannot forbid it: MySQL 8.0.16+ rejects any CHECK
 * naming a column that carries a foreign key referential action, and `rule_id`
 * cascades so that deleting a rule in core's rule builder does not error. Such
 * a row is inert -- the resolver skips it -- which is exactly why it must not
 * be creatable here: inert misconfiguration is invisible misconfiguration.
 *
 * A zero or negative weight is refused for the same reason. Zero can never win
 * a bucket, and a negative one reduces the arms' total, which can drive it to
 * zero and lose the assignment for every customer in that channel.
 */
export function isSavable(row: AssignmentLike): boolean {
    if (typeof row.strategyId !== 'string' || row.strategyId === '') {
        return false;
    }

    if (row.kind === 'pin') {
        return typeof row.customerId === 'string' && row.customerId !== '';
    }

    if (row.kind === 'rule') {
        return typeof row.ruleId === 'string' && row.ruleId !== '';
    }

    if (row.kind === 'split') {
        return typeof row.weight === 'number' && row.weight > 0;
    }

    return false;
}

/**
 * Whether `row` duplicates an already-known pin for the same customer at the
 * same scope (same `salesChannelId`, treating `null` and `''` as the same
 * "every channel" scope -- the select can hand back either). Same shape as
 * the `rule_id` gap `isSavable` closes above: MySQL's unique index treats
 * NULL as distinct, so it catches a duplicate CHANNEL-scoped pin but not two
 * GLOBAL pins for the same customer -- `(NULL, customerX)` twice is accepted
 * by the schema. A duplicate does not break anything; the resolver orders
 * deterministically and takes the first row. It just means one of the two
 * pins the merchant wrote silently never applies, with nothing saying which.
 *
 * A channel-scoped pin and a global pin for the same customer are NOT
 * duplicates -- that pair is the whole point of scoping.
 *
 * Compared by object identity, not id: every row already carries a real id
 * from the moment it is created (the repository assigns one before save), so
 * an id comparison could not tell "this row, being re-saved" apart from a
 * coincidentally-matching id -- which never happens -- from "the exact same
 * in-memory row", the actual distinction this function needs. `row` is
 * always one of the objects `existing` was built from, never a fresh copy of
 * it, so identity is both simpler and correct.
 */
export function isDuplicatePin(row: AssignmentLike, existing: AssignmentLike[]): boolean {
    if (row.kind !== 'pin') {
        return false;
    }

    const scope = (salesChannelId: string | null): string => salesChannelId ?? '';

    return existing.some(
        (other) =>
            other !== row &&
            other.kind === 'pin' &&
            other.customerId === row.customerId &&
            scope(other.salesChannelId) === scope(row.salesChannelId),
    );
}

/**
 * Each arm's share of the arms' OWN sum, so a merchant typing 1 and 4 sees 20%
 * and 80% instead of a validation error demanding they total 100. Rounded to
 * one decimal for display only -- the resolver buckets on the raw integers, so
 * three equal arms showing 33.3% each is correct, not a rounding bug to fix.
 */
export function splitShares<T extends { weight: number | null }>(arms: T[]): { arm: T; percent: number }[] {
    const total = arms.reduce((sum, arm) => sum + Math.max(arm.weight ?? 0, 0), 0);

    return arms.map((arm) => ({
        arm,
        percent: total === 0 ? 0 : Math.round((Math.max(arm.weight ?? 0, 0) / total) * 1000) / 10,
    }));
}

/**
 * The assignment sources behind a set of passes, commonest first.
 *
 * This is what makes a silent fall-through legible: the rule rung skips itself
 * with only a log warning when a quote's customer has no active shipping
 * address, so a strategy row reading `rule: 12, config: 40` means the rule
 * matched far less often than the merchant believes. A null source is a row
 * written before the column existed and is not counted.
 */
export function spreadLabel(sources: (string | null)[]): { source: string; count: number }[] {
    const counts = new Map<string, number>();

    for (const source of sources) {
        if (typeof source === 'string' && source !== '') {
            counts.set(source, (counts.get(source) ?? 0) + 1);
        }
    }

    return [...counts.entries()]
        .map(([source, count]) => ({ source, count }))
        .sort((a, b) => b.count - a.count || a.source.localeCompare(b.source));
}
