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

/**
 * Which snippet key names each assignment source in the measures list (see
 * `spreadLabel` below and the list page's `assignmentSourceLabel`). Kept as
 * one exported map, not a literal object duplicated in the list page, so
 * assignment.check.mjs can pin it end to end: PHP enum -> ASSIGNMENT_SOURCES
 * -> this map -> a real key in both snippet/en.json and snippet/de.json. A
 * fifth PHP enum case with no entry here would previously have rendered as
 * the raw enum value with nothing failing -- admin vocabulary drifting from a
 * backend enum has shipped twice in this project.
 */
export const ASSIGNMENT_SOURCE_SNIPPET_KEYS: Record<(typeof ASSIGNMENT_SOURCES)[number], string> = {
    pin: 'sourcePin',
    rule: 'sourceRule',
    split: 'sourceSplit',
    config: 'sourceConfig',
};

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
 * Whether `row` duplicates an already-known rule binding at the same scope --
 * same shape as isDuplicatePin above, for the other half of the gap the table
 * cannot close: there is no unique index on `rule_id` at all, so two rows can
 * bind the SAME rule at the SAME scope, and which one the resolver honours is
 * merely deterministic, not meaningful. Unlike isDuplicatePin, cross-scope
 * duplicates are never flagged, because a channel binding is now the intended
 * override for a global one (see StrategyAssignmentResolver's channel-first
 * walk) -- refusing that pair would refuse the normal way to scope a rule.
 *
 * Compared by object identity, not id, for the same reason isDuplicatePin is.
 */
export function isDuplicateRule(row: AssignmentLike, existing: AssignmentLike[]): boolean {
    if (row.kind !== 'rule') {
        return false;
    }

    const scope = (salesChannelId: string | null): string => salesChannelId ?? '';

    return existing.some(
        (other) =>
            other !== row &&
            other.kind === 'rule' &&
            other.ruleId === row.ruleId &&
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
 * `splitShares`, but grouped by sales channel first, because a bucket hash
 * includes the channel (`sha1(customerId . salesChannelId)`, see the design
 * doc and `StrategyAssignmentResolver`) -- a 1:1 split in channel A and a 1:1
 * split in channel B are two independent experiments, not four arms of one.
 * Feeding all channels' arms into a single `splitShares()` call would show
 * 25/25/25/25 for two separate 50/50 splits: wrong, while looking plausible,
 * which is exactly the failure mode worth a dedicated function and its own
 * assertions rather than a second expression inline wherever this is used.
 *
 * `null` and `''` group together, the same "every channel" scope
 * `isDuplicatePin` uses -- a cleared `sw-entity-single-select` can hand back
 * either. Grouping (a `Map`, not a sort) also leaves same-channel rows
 * adjacent in the flat result, in first-appearance order.
 */
export function groupedSplitShares<T extends { weight: number | null; salesChannelId: string | null }>(
    arms: T[],
): { arm: T; percent: number }[] {
    const scope = (salesChannelId: string | null): string => salesChannelId ?? '';
    const order: string[] = [];
    const byScope = new Map<string, T[]>();

    for (const arm of arms) {
        const key = scope(arm.salesChannelId);

        if (!byScope.has(key)) {
            byScope.set(key, []);
            order.push(key);
        }

        byScope.get(key)?.push(arm);
    }

    return order.flatMap((key) => splitShares(byScope.get(key) ?? []));
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

/**
 * What `loadAssignments()` must do with a fresh search result instead of
 * `this.assignments = [...serverRows]`.
 *
 * addPin()/addRule()/addArm() push a blank, unsaved row straight into
 * `this.assignments`, and saveAssignment()/removeAssignment() both reload
 * after every write. A flat replace discarded every OTHER unsaved row on the
 * page the moment any one row was saved or removed -- a merchant filling in
 * three split arms and saving the first silently lost the other two, with
 * nothing saying so.
 *
 * `isNew` is a predicate rather than a property check so this stays framework
 * -free: the real predicate is an Entity's `isNew()` method (not `_isNew`,
 * verified against the installed core), which this pure function has no
 * business importing.
 *
 * A local row that is NOT new is dropped in favour of its server copy rather
 * than kept -- comparing by identity, the same way `isDuplicatePin` does,
 * would treat "this row, freshly reloaded" as unrelated to "the same row from
 * before", duplicating it. A saved row absent from the server result (deleted
 * by someone else since the last load) is dropped, not resurrected: the
 * point of reloading at all is to see deletions other admins made.
 *
 * `isNew` alone cannot tell "never saved" from "saved a moment ago": Shopware's
 * own repository (`core/data/repository.data.ts`, `sendChanges()`) POSTs a new
 * entity but never clears its `_isNew` flag afterwards, and nothing else under
 * `core/data` does either -- verified against the installed core, not assumed.
 * So the very row this page just saved still reports `isNew() === true` on the
 * next call here, and without the id check below it would be kept ALONGSIDE
 * the fresh server copy of the same row: a permanent duplicate that re-merges
 * on every future reload, a phantom second pin that blocks `isDuplicatePin`
 * from letting the merchant re-save their own pin, and a row whose delete
 * takes removeAssignment()'s "unsaved, splice locally" branch forever, so no
 * DELETE is ever sent for it. The id check is what actually distinguishes the
 * two cases: a row the server has never seen keeps an id absent from
 * `serverRows`; a row that was just saved has an id the server now echoes
 * back. Do not simplify this back to a bare `isNew` filter.
 */
export function mergeUnsaved<T extends { id: string }>(serverRows: T[], localRows: T[], isNew: (row: T) => boolean): T[] {
    const serverIds = new Set(serverRows.map((row) => row.id));

    return [...serverRows, ...localRows.filter((row) => isNew(row) && !serverIds.has(row.id))];
}
