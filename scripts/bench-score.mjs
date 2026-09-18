#!/usr/bin/env node
/**
 * Scores a bench run's JSONL (one decision-record pass per line) by grouping
 * rows into (strategyVersionId, model) cells and running each cell through
 * the admin's own measure code -- foldToQuotes() from decision.ts, then
 * autoExecutionRate() and escalationResolution() from measures.ts -- so the
 * bench readout and the merchant dashboard can never drift apart. See those
 * modules' own docblocks for what each figure means.
 *
 * Usage: node scripts/bench-score.mjs var/bench/<runId>.jsonl
 */
import { readFileSync } from 'node:fs';
import { foldToQuotes } from '../src/Resources/app/administration/src/module/merchant-quote-agent/decision.ts';
import * as measures from '../src/Resources/app/administration/src/module/merchant-quote-agent/measures.ts';

const { autoExecutionRate, escalationResolution, formatSpan } = measures;

// tokensPerNegotiation lands with admin PR #159 (Track B), not yet merged
// into this branch's measures.ts. Feature-detect rather than reimplementing
// it -- a local copy is the exact drift the shared contract forbids.
const tokensPerNegotiation = typeof measures.tokensPerNegotiation === 'function' ? measures.tokensPerNegotiation : null;

function readRows(path) {
    return readFileSync(path, 'utf8')
        .split('\n')
        .filter((line) => line.trim() !== '')
        .map((line) => JSON.parse(line));
}

function groupKey(row) {
    return JSON.stringify([row.strategyVersionId, row.model]);
}

/**
 * The admin sorts createdAt DESC before folding (see
 * merchant-quote-agent-list/index.ts's `criteria.addSorting(Criteria.sort('createdAt', 'DESC'))`
 * feeding `foldToQuotes()`). foldToQuotes()'s own docblock says decisions
 * "must arrive newest-first" -- the JSONL is written oldest-first per quote
 * (DecisionRowMapper's `ORDER BY created_at ASC`), so skipping this sort
 * silently swaps in the OLDEST pass as each quote's "latest" state.
 */
function scoreGroup(rows) {
    const newestFirst = [...rows].sort((a, b) => Date.parse(b.createdAt) - Date.parse(a.createdAt));
    const folded = foldToQuotes(newestFirst);

    return {
        quotes: folded.length,
        rows: rows.length,
        autoExecution: autoExecutionRate(folded),
        escalation: escalationResolution(rows, null),
        tokens: tokensPerNegotiation ? tokensPerNegotiation(rows) : null,
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
    const unattributed = rows.filter((row) => row.strategyVersionId === null || row.strategyVersionId === undefined);
    const attributed = rows.filter((row) => row.strategyVersionId !== null && row.strategyVersionId !== undefined);
    const failedQuoteIds = new Set(rows.filter((row) => row.orderFailure != null).map((row) => row.quoteId));

    const groups = new Map();
    for (const row of attributed) {
        const key = groupKey(row);
        if (!groups.has(key)) {
            groups.set(key, []);
        }
        groups.get(key).push(row);
    }

    // No rank: sorted by (strategyVersionId, model) only, never by a measure.
    // B2B volume does not reach significance and the admin table declares no
    // winner -- the scorer must not either.
    const sortedKeys = [...groups.keys()].sort((a, b) => a.localeCompare(b));

    console.log(`Run: ${path}`);
    console.log(
        `${rows.length} rows, ${groups.size} (strategy, model) groups, `
        + `${unattributed.length} unattributed rows, ${failedQuoteIds.size} quotes with an orderFailure.\n`,
    );

    if (!tokensPerNegotiation) {
        console.log(
            "tokensPerNegotiation: unavailable -- not exported by this branch's measures.ts "
            + '(lands with admin PR #159, Track B, not yet merged). Not reimplemented here.\n',
        );
    }
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
        if (tokensPerNegotiation) {
            console.log(`  tokensPerNegotiation: ${JSON.stringify(score.tokens)}`);
        }
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
}

main();
