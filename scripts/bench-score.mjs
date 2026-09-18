#!/usr/bin/env node
/**
 * Scores a bench run's JSONL (one decision-record pass per line, plus one
 * failure row per cell that threw before producing any decision row) by
 * grouping rows into (strategyVersionId, model) cells and running each cell
 * through the admin's own measure code -- foldToQuotes() from decision.ts,
 * then autoExecutionRate(), escalationResolution() and tokensPerNegotiation()
 * from measures.ts -- so the bench readout and the merchant dashboard can
 * never drift apart. See those modules' own docblocks for what each figure
 * means.
 *
 * A failure row (`cellFailure: true`, written by `BenchRunTest::attemptCell`)
 * is never a negotiation, so it is counted as a failed cell and excluded from
 * every group below rather than folded in as if it were a real pass.
 *
 * Usage: node scripts/bench-score.mjs var/bench/<runId>.jsonl
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { realpathSync } from 'node:fs';
import { foldToQuotes } from '../src/Resources/app/administration/src/module/merchant-quote-agent/decision.ts';
import {
    autoExecutionRate,
    escalationResolution,
    formatSpan,
    tokensPerNegotiation,
} from '../src/Resources/app/administration/src/module/merchant-quote-agent/measures.ts';

export function readRows(path) {
    return readFileSync(path, 'utf8')
        .split('\n')
        .filter((line) => line.trim() !== '')
        .map((line) => JSON.parse(line));
}

/**
 * Splits a run's lines into real decision-record passes and failure rows.
 * `cellFailure: true` is the explicit discriminator a failure row carries
 * (see `DecisionRowMapper::toFailureRow` in `BenchRunTest.php`) -- it is
 * checked directly rather than inferred from a missing field, because a
 * failure row still carries `strategyVersionId`/`model` (so it can be
 * reported against the cell it belongs to), which is exactly what would make
 * an absent-field check misclassify it as a real, if sparse, decision row.
 *
 * @return {{ failureRows: object[], decisionRows: object[] }}
 */
export function partitionRows(rows) {
    const failureRows = rows.filter((row) => row.cellFailure === true);
    const decisionRows = rows.filter((row) => row.cellFailure !== true);
    return { failureRows, decisionRows };
}

export function groupKey(row) {
    return JSON.stringify([row.strategyVersionId, row.model]);
}

/**
 * Groups decision rows (never failure rows -- callers pass the
 * `decisionRows` half of `partitionRows()`) into (strategyVersionId, model)
 * cells.
 */
export function buildGroups(decisionRows) {
    const unattributed = decisionRows.filter((row) => row.strategyVersionId === null || row.strategyVersionId === undefined);
    const attributed = decisionRows.filter((row) => row.strategyVersionId !== null && row.strategyVersionId !== undefined);

    const groups = new Map();
    for (const row of attributed) {
        const key = groupKey(row);
        if (!groups.has(key)) {
            groups.set(key, []);
        }
        groups.get(key).push(row);
    }

    return { unattributed, attributed, groups };
}

/**
 * The admin sorts createdAt DESC before folding (see
 * merchant-quote-agent-list/index.ts's `criteria.addSorting(Criteria.sort('createdAt', 'DESC'))`
 * feeding `foldToQuotes()`). foldToQuotes()'s own docblock says decisions
 * "must arrive newest-first" -- the JSONL is written oldest-first per quote
 * (DecisionRowMapper's `ORDER BY created_at ASC`), so skipping this sort
 * silently swaps in the OLDEST pass as each quote's "latest" state.
 */
export function scoreGroup(rows) {
    const newestFirst = [...rows].sort((a, b) => Date.parse(b.createdAt) - Date.parse(a.createdAt));
    const folded = foldToQuotes(newestFirst);

    return {
        quotes: folded.length,
        rows: rows.length,
        autoExecution: autoExecutionRate(folded),
        escalation: escalationResolution(rows, null),
        tokens: tokensPerNegotiation(rows),
    };
}

function main() {
    const path = process.argv[2];
    if (!path) {
        console.error('Usage: node scripts/bench-score.mjs <run.jsonl>');
        process.exitCode = 1;
        return;
    }

    const rows = readRows(path);
    const { failureRows, decisionRows } = partitionRows(rows);
    const { unattributed, attributed, groups } = buildGroups(decisionRows);
    const failedQuoteIds = new Set(decisionRows.filter((row) => row.orderFailure != null).map((row) => row.quoteId));

    // No rank: sorted by (strategyVersionId, model) only, never by a measure.
    // B2B volume does not reach significance and the admin table declares no
    // winner -- the scorer must not either.
    const sortedKeys = [...groups.keys()].sort((a, b) => a.localeCompare(b));

    console.log(`Run: ${path}`);
    console.log(
        `${rows.length} lines (${decisionRows.length} decision rows, ${failureRows.length} failed cells), `
        + `${groups.size} (strategy, model) groups, ${unattributed.length} unattributed rows, `
        + `${failedQuoteIds.size} quotes with an orderFailure.\n`,
    );

    console.log(
        'priceRetention / dealCycleTime: unavailable -- both need quoteRows and orderDates, '
        + 'which a JSONL of decision records does not carry. Not fabricated here.\n',
    );

    for (const key of sortedKeys) {
        const [strategyVersionId, model] = JSON.parse(key);
        const score = scoreGroup(groups.get(key));
        const auto = score.autoExecution;
        const esc = score.escalation;

        console.log(`strategy ${strategyVersionId}  model ${model}`);
        console.log(`  N (quotes): ${score.quotes}  (${score.rows} decision rows)`);
        console.log(
            `  autoExecutionRate: ${auto.rate === null ? 'n/a' : `${auto.rate.toFixed(1)}%`} `
            + `(${auto.escalated}/${auto.total} escalated)`,
        );
        console.log(
            `  escalationResolution: mean ${formatSpan(esc.meanMs)} over ${esc.measured} resolved, ${esc.open} open`
            + `${esc.withinSla === null ? '' : `, ${esc.withinSla} within SLA`}`,
        );
        console.log(
            `  tokensPerNegotiation: mean ${score.tokens.meanTokens === null ? 'n/a' : score.tokens.meanTokens.toFixed(0)} `
            + `over ${score.tokens.measured} measured passes across ${score.tokens.quotes} quotes`,
        );
        console.log('');
    }

    if (unattributed.length > 0) {
        console.log(
            `Unattributed: ${unattributed.length} rows carry a null strategyVersionId and are excluded above. `
            + 'These are not a strategy: an unreachable model, or a broken CellSettings wiring, lands here with '
            + 'no strategy and no model, which silently drops a column from the comparison if left uncounted.',
        );
    }

    if (failedQuoteIds.size > 0) {
        console.log(
            `Failed: ${failedQuoteIds.size} quotes carry a non-null orderFailure (negotiation completed but order `
            + 'conversion failed). Their decision rows are still included in the measures above -- orderFailure '
            + "does not affect the decision record's own fields -- called out here so a run does not omit them.",
        );
    }

    if (failureRows.length > 0) {
        console.log(
            `Failed cells: ${failureRows.length} cell(s) threw before producing a single decision row. Excluded `
            + 'from every group above -- a cell that never negotiated must never be folded in as if it had:',
        );
        for (const row of failureRows) {
            console.log(
                `  ${row.scenarioId} / ${row.strategyVersionId ?? 'null'} / ${row.model ?? 'null'}: `
                + `${row.failureClass}: ${row.failureMessage}`,
            );
        }
    }
}

// Only run when invoked as a script (`node scripts/bench-score.mjs ...`), not
// when imported by `bench-score.check.mjs` -- realpath so a relative argv[1]
// (as typed on the command line) still matches this file's absolute URL.
if (process.argv[1] && realpathSync(process.argv[1]) === fileURLToPath(import.meta.url)) {
    main();
}
