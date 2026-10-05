/**
 * Stage 3 and 4 of the eval (spec 2026-09-28-claude-code-evals-design): the
 * transcript the judge sees, the judge's raw result, H8, and the verdict.
 * Pure functions; the CLI in scripts/eval-check.mjs does the file I/O.
 */
import { HARD, MONEY, RATE, baselineDiscount, checkNegotiation, fail, goodsFactor, groupNegotiations, na, pass } from './checks.mjs';

export const RUBRIC = ['J1', 'J2', 'J3', 'J4'];

/**
 * What the judge sees: the scenario's extra rubric lines, then each round's
 * buyer comment and agent reply. No outcomes, no totals, no expectations --
 * it extracts figures blind.
 */
export function transcript(scenario, negotiation) {
    const extras = scenario.expect?.judge ?? [];
    const out = ['EXTRA RUBRIC ITEMS:', ...(extras.length === 0 ? ['(none)'] : extras.map((text, i) => `J5.${i + 1}: ${text}`)), '', 'TRANSCRIPT:'];
    for (const row of negotiation.rows) {
        const buyer = row.buyerAsk ?? (row.round === 1 && scenario.openingAsk ? scenario.openingAsk : '(no comment this round)');
        out.push('', `ROUND ${row.round}`, `BUYER: ${buyer}`, `AGENT: ${row.replyToBuyer ?? '(no reply)'}`);
    }
    return `${out.join('\n')}\n`;
}

/**
 * Every number a reply may legitimately state up to this round: the opening
 * and each written total (net and grossed up), unit prices and line totals
 * with and without the quote-wide factor, the saving and the discount off the
 * round-1 baseline for each of those states, and each pass's own delta.
 *
 * ponytail: earlier rounds' figures also count, so "down from 8% to 10%"
 * passes; the ceiling is that a reply stating an older figure as current also
 * passes. Tighten to per-kind "current vs previous" if that ever bites.
 */
export function figureCandidates(rows, row) {
    const base = rows[0];
    const states = [
        { net: base.totalNetBefore, gross: base.totalGrossBefore, lines: base.linesBefore },
        ...rows.filter((r) => r.round <= row.round).map((r) => ({
            net: r.totalNetAfter ?? r.totalNetBefore,
            gross: r.totalGrossAfter ?? r.totalGrossBefore,
            lines: r.linesAfter ?? r.linesBefore,
            before: r.totalNetBefore,
        })),
    ];
    const money = [];
    const percent = [];
    for (const state of states) {
        if (!(state.net > 0)) continue;
        const grossFactor = state.gross > 0 ? state.gross / state.net : 1;
        money.push(state.net, state.net * grossFactor);
        if (base.totalNetBefore > 0) {
            const saving = base.totalNetBefore - state.net;
            money.push(saving, saving * grossFactor);
        }
        if (state.before > 0) percent.push(((state.before - state.net) / state.before) * 100); // "another 5%": this pass alone
        const discount = baselineDiscount(rows, state.net);
        if (discount !== null) percent.push(discount); // no positive baseline: no percent candidate, never NaN
        const lines = Array.isArray(state.lines) ? state.lines : [];
        const factor = goodsFactor(lines);
        for (const line of lines) {
            if (!(line.unitPriceNet > 0)) continue;
            for (const value of [line.unitPriceNet, line.unitPriceNet * factor, line.totalNet, line.totalNet * factor]) {
                money.push(value, value * grossFactor);
            }
        }
    }
    return { money, percent };
}

export function h8StatedFigures(rows, judgment) {
    if (!judgment) return { status: 'judge_error', reason: 'no judgment for this negotiation' };
    let stated = 0;
    for (const round of judgment.rounds) {
        const row = rows.find((r) => r.round === round.round);
        if (!row) return { status: 'judge_error', reason: `the judge reported round ${round.round}, which this negotiation does not have` };
        const candidates = figureCandidates(rows, row);
        for (const figure of round.statedFigures) {
            stated++;
            const tolerance = Math.max(figure.kind === 'percent' ? RATE : MONEY, 0.5 * 10 ** -figure.decimals);
            const pool = figure.kind === 'percent' ? candidates.percent : candidates.money;
            if (!pool.some((candidate) => Math.abs(candidate - figure.value) <= tolerance)) {
                return fail(`round ${row.round}: the reply states "${figure.quote}" (${figure.value}), which matches nothing the quote carries`);
            }
        }
    }
    return stated === 0 ? na('no reply stated a figure') : pass();
}

export function unwrapJudgeResult(raw) {
    let parsed;
    try {
        parsed = JSON.parse(raw);
    } catch {
        return { error: 'claude -p did not return JSON' };
    }
    if (parsed.is_error === true || parsed.subtype !== 'success') return { error: `claude -p ended as ${parsed.subtype ?? 'an error'}` };
    const judgment = parsed.structured_output;
    if (!judgment || !Array.isArray(judgment.rounds) || !Array.isArray(judgment.rubric)) return { error: 'no structured_output in the result' };
    return { judgment, costUsd: parsed.total_cost_usd ?? null };
}

/** What a judge call cost, from its raw `claude -p` result -- an error result (a budget hit) cost money too. */
export function judgeCostUsd(raw) {
    try {
        const cost = JSON.parse(raw).total_cost_usd;
        return Number.isFinite(cost) ? cost : null;
    } catch {
        return null;
    }
}

export function canaryMismatches(judgment, labels) {
    const out = [];
    for (const [id, want] of Object.entries(labels.rubric)) {
        const got = judgment.rubric.find((item) => item.id === id)?.verdict;
        if (got !== want) out.push(`${id}: labelled ${want}, the judge said ${got ?? 'nothing'}`);
    }
    for (const expected of labels.figures) {
        const found = judgment.rounds.some((r) => r.round === expected.round && r.statedFigures.some((f) => Math.abs(f.value - expected.value) <= MONEY));
        if (!found) out.push(`round ${expected.round}: the figure ${expected.value} was not extracted`);
    }
    return out;
}

const missing = (reason) => ({ H1: na(reason), H2: na(reason), H3: na(reason), H4: na(reason), H5: na(reason), H6: na(reason), H7: fail(reason), H9: na(reason), H10: na(reason) });

function aggregateHard(statuses) {
    if (statuses.some((s) => s.status === 'fail')) return 'fail';
    if (statuses.some((s) => s.status === 'judge_error')) return 'err';
    if (statuses.every((s) => s.status === 'n/a')) return 'n/a';
    return 'pass';
}

function scenarioVerdict(scenario, reps, negotiations, judgments) {
    const threshold = Math.ceil((reps * 2) / 3);
    const perRep = [];
    for (let rep = 1; rep <= reps; rep++) {
        const key = `${scenario.id}#${rep}`;
        const negotiation = negotiations.get(key);
        const hard = negotiation ? checkNegotiation(scenario, negotiation) : missing('no JSONL line for this negotiation');
        const judgment = judgments.get(key) ?? null;
        hard.H8 = negotiation && !negotiation.failure ? h8StatedFigures(negotiation.rows, judgment) : na('no negotiation to judge');
        perRep.push({ hard, judgment });
    }
    const checks = {};
    for (const id of [...HARD, 'H8']) {
        const reasons = perRep.map((r) => r.hard[id]);
        checks[id] = { result: aggregateHard(reasons), passes: reasons.filter((r) => r.status === 'pass').length, of: reps, reps: reasons };
    }
    const rubricIds = [...RUBRIC, ...(scenario.expect?.judge ?? []).map((_, i) => `J5.${i + 1}`)];
    for (const id of rubricIds) {
        const reasons = perRep.map(({ judgment }) => {
            const item = judgment?.rubric.find((r) => r.id === id);
            if (!item) return { status: 'judge_error', reason: judgment ? `the judge returned no ${id}` : 'no judgment' };
            return { status: item.verdict === 'fail' ? 'fail' : 'pass', reason: item.reason };
        });
        const passes = reasons.filter((r) => r.status === 'pass').length;
        const errors = reasons.filter((r) => r.status === 'judge_error').length;
        const result = passes >= threshold ? 'pass' : passes + errors >= threshold ? 'err' : 'fail';
        checks[id] = { result, passes, of: reps, reps: reasons };
    }
    const results = Object.values(checks).map((c) => c.result);
    // Any rep's judge error marks the scenario err (exit 2), even when its item still clears 2/3.
    const judgeError = Object.values(checks).some((c) => c.reps.some((r) => r.status === 'judge_error'));
    const status = results.includes('fail') ? 'fail' : results.includes('err') || judgeError ? 'err' : 'pass';
    return { id: scenario.id, description: scenario.description, tags: scenario.tags, status, checks };
}

const sum = (values) => values.reduce((total, value) => total + (Number.isFinite(value) ? value : 0), 0);

/** Median and max of the finite values, in ms; null when there are none. */
function spread(values) {
    const sorted = values.filter(Number.isFinite).sort((a, b) => a - b);
    if (sorted.length === 0) return null;
    const mid = Math.floor(sorted.length / 2);
    const median = sorted.length % 2 === 1 ? sorted[mid] : (sorted[mid - 1] + sorted[mid]) / 2;
    return { count: sorted.length, medianMs: Math.round(median), maxMs: sorted[sorted.length - 1] };
}

/**
 * Tokens, latency and judge cost over some decision rows. Tokens are the
 * shop's model, summed over every pass (no price: the provider's is unknown);
 * `buyerLatency` is what the buyer waited per round, `passDuration` the
 * shop's own `durationMs` per pass.
 */
function tally(rows, costs) {
    return {
        passes: rows.length,
        promptTokens: sum(rows.map((row) => row.promptTokens)),
        completionTokens: sum(rows.map((row) => row.completionTokens)),
        buyerLatency: spread(rows.map((row) => row.buyerLatencyMs)),
        passDuration: spread(rows.map((row) => row.durationMs)),
        judgeCostUsd: costs.length === 0 ? null : Number(sum(costs).toFixed(6)),
    };
}

/** Per scenario and run-wide; the total is the sum of the scenarios listed, so it adds up. */
export function usage(scenarios, rows, judgeCosts) {
    const ids = new Set(scenarios.map((s) => s.id));
    const passes = rows.filter((row) => row.cellFailure !== true && ids.has(row.scenarioId));
    const costs = (keep) => [...judgeCosts].filter(([key]) => keep(key.slice(0, key.lastIndexOf('#')))).map(([, cost]) => cost);
    return {
        total: tally(passes, costs((id) => ids.has(id))),
        scenarios: Object.fromEntries(scenarios.map((s) => [s.id, tally(passes.filter((row) => row.scenarioId === s.id), costs((id) => id === s.id))])),
    };
}

/** Per tag: how many scenarios carry it and how many of those pass; `err` counts as not passing. */
export function tagPassRates(results) {
    const tags = {};
    for (const result of results) {
        for (const tag of result.tags) {
            tags[tag] ??= { scenarios: 0, passing: 0 };
            tags[tag].scenarios++;
            if (result.status === 'pass') tags[tag].passing++;
        }
    }
    return Object.fromEntries(Object.keys(tags).sort().map((tag) => [tag, { ...tags[tag], passRate: Number((tags[tag].passing / tags[tag].scenarios).toFixed(3)) }]));
}

/** `judgeCosts`: `<scenarioId>#<rep>` -> USD, for the judge calls that reported one. */
export function verdict({ scenarios, reps, rows, judgments, judgeCosts = new Map() }) {
    const negotiations = new Map(groupNegotiations(rows).map((n) => [`${n.scenarioId}#${n.rep}`, n]));
    const results = scenarios.map((scenario) => scenarioVerdict(scenario, reps, negotiations, judgments));
    const failed = results.some((r) => r.status === 'fail');
    const errored = results.some((r) => r.status === 'err');
    return { status: failed ? 'fail' : errored ? 'err' : 'pass', exitCode: failed ? 1 : errored ? 2 : 0, reps, scenarios: results, tags: tagPassRates(results), usage: usage(scenarios, rows, judgeCosts) };
}

export function formatTable(result) {
    const columns = ['H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'H7', 'H8', 'H9', 'H10', 'J1', 'J2', 'J3', 'J4'];
    const cell = (check) => (!check ? '' : check.result === 'n/a' ? 'n/a' : check.result === 'err' ? 'err' : `${check.passes}/${check.of}`);
    const width = Math.max(...result.scenarios.map((s) => s.id.length), 8);
    const lines = [`${'scenario'.padEnd(width)}  ${columns.map((c) => c.padStart(4)).join(' ')}   J5`];
    for (const s of result.scenarios) {
        const j5 = Object.keys(s.checks).filter((id) => id.startsWith('J5.')).map((id) => cell(s.checks[id])).join(',');
        lines.push(`${s.id.padEnd(width)}  ${columns.map((c) => cell(s.checks[c]).padStart(4)).join(' ')}   ${j5 || '-'}  ${s.status.toUpperCase()}`);
    }
    const passing = result.scenarios.filter((s) => s.status === 'pass').length;
    const { total } = result.usage;
    const seconds = (spread) => (spread ? `median ${(spread.medianMs / 1000).toFixed(1)} s, max ${(spread.maxMs / 1000).toFixed(1)} s` : 'n/a');
    lines.push(
        '',
        `usage: ${total.passes} passes, ${total.promptTokens} prompt + ${total.completionTokens} completion tokens, buyer wait ${seconds(total.buyerLatency)}, judge ${total.judgeCostUsd === null ? 'cost n/a' : `$${total.judgeCostUsd.toFixed(2)}`}`,
        `${passing}/${result.scenarios.length} scenarios pass -- exit ${result.exitCode}`,
    );
    return lines.join('\n');
}
