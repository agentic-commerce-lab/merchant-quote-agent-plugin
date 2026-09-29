/**
 * Hard checks H1-H7 and H9 (spec 2026-09-28-claude-code-evals-design,
 * "Stage 2"). Pure functions over one negotiation's JSONL rows: no I/O, no
 * model. H8 needs the judge's output and lives in verdict.mjs.
 *
 * Each check returns { status: 'pass' | 'fail' | 'n/a', reason }. `n/a`
 * means there was nothing to look at (no offer written, no margin set),
 * never "could not tell": a check that cannot tell fails with a reason.
 */

/** NegotiationOutcome's backing values -- the only vocabulary a row may carry. Defined once, in scenarios.mjs. */
import { OUTCOMES } from './scenarios.mjs';

export { OUTCOMES };
/** Half a cent, and the 0.01 pp DiscountTotalViolation allows. */
export const MONEY = 0.005;
export const RATE = 0.01;
export const HARD = ['H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'H7', 'H9'];

export const pass = () => ({ status: 'pass', reason: null });
export const na = (reason) => ({ status: 'n/a', reason });
export const fail = (reason) => ({ status: 'fail', reason });

const written = (row) => row.totalNetAfter !== null && row.totalNetAfter !== undefined;

// Mirror MoneyMath::floorToCent and MarginFloors::ceilToCent: both do
// ceil/floor(round($value * 100, precision: 6)) / 100 -- clear the float noise
// at 1e-6 of a cent first. toFixed(6) is that round() for every value a price
// takes; they could only part on an exact tie in the seventh decimal of a cent.
export const ceilToCent = (value) => Math.ceil(Number((value * 100).toFixed(6))) / 100;
export const floorToCent = (value) => Math.floor(Number((value * 100).toFixed(6))) / 100;

/** Policy\GoodsFactor: the share of a positive line's price the buyer pays once negative lines come off. */
export function goodsFactor(lines) {
    let positive = 0;
    let negative = 0;
    for (const line of lines) {
        const total = line.unitPriceNet * line.quantity;
        if (total > 0) positive += total;
        else negative += total;
    }
    return positive > 0 ? Math.min(1, Math.max(0, (positive + negative) / positive)) : 1;
}

/** Percent off round 1's `totalNetBefore` -- the anchored baseline, never the previous round. */
export function baselineDiscount(rows, totalNet) {
    const base = rows[0].totalNetBefore;
    return ((base - totalNet) / base) * 100;
}

export function groupNegotiations(rows) {
    const groups = new Map();
    for (const row of rows) {
        const rep = row.rep ?? 1;
        const key = `${row.scenarioId}#${rep}`;
        const group = groups.get(key) ?? { scenarioId: row.scenarioId, rep, rows: [], failure: null };
        if (row.cellFailure === true) group.failure = row;
        else group.rows.push(row);
        groups.set(key, group);
    }
    for (const group of groups.values()) group.rows.sort((a, b) => a.round - b.round);
    return [...groups.values()];
}

export function h1FirstOutcome(scenario, rows) {
    const expected = scenario.expect?.firstOutcome ?? [];
    if (expected.length === 0) return na('the scenario declares no firstOutcome');
    const actual = rows[0]?.outcome;
    if (!OUTCOMES.includes(actual)) {
        return fail(`round 1 outcome "${actual}" is not a NegotiationOutcome value -- vocabulary drift`);
    }
    return expected.includes(actual) ? pass() : fail(`round 1 was ${actual}, expected one of: ${expected.join(', ')}`);
}

export function h2Cap(rows) {
    const offers = rows.filter(written);
    if (offers.length === 0) return na('no pass wrote an offer');
    if (!(rows[0].totalNetBefore > 0)) return fail('round 1 has no totalNetBefore to measure against');
    for (const row of offers) {
        const discount = baselineDiscount(rows, row.totalNetAfter);
        if (discount > row.policy.maxDiscountPercent + RATE) {
            return fail(`round ${row.round}: ${discount.toFixed(4)}% off the round-1 total exceeds the ${row.policy.maxDiscountPercent}% cap`);
        }
    }
    return pass();
}

/** Mirrors Policy\MarginFloors::of over each pass's own linesBefore. */
export function h3MarginFloor(rows) {
    const margin = rows[0]?.policy?.minMarginPercent;
    if (margin === null || margin === undefined) return na('no minMarginPercent in the policy');
    const prices = rows[0].purchasePricesNet ?? {};
    if (Object.keys(prices).length === 0) return fail('minMarginPercent is set but no purchase price reached the bench');
    const passes = rows.filter((row) => Array.isArray(row.linesAfter) && Array.isArray(row.linesBefore));
    if (passes.length === 0) return na('no pass wrote an offer');
    for (const row of passes) {
        const before = goodsFactor(row.linesBefore);
        const after = goodsFactor(row.linesAfter);
        for (const line of row.linesAfter) {
            const purchase = prices[line.productId];
            const was = row.linesBefore.find((candidate) => candidate.lineItemId === line.lineItemId);
            if (purchase === undefined || !was || !(was.unitPriceNet > 0)) continue;
            const floor = Math.min(ceilToCent(purchase * (1 + margin / 100)), floorToCent(was.unitPriceNet * before));
            const effective = line.unitPriceNet * after;
            if (effective < floor - MONEY) {
                return fail(`round ${row.round}: line ${line.lineItemId} costs ${effective.toFixed(4)} net per unit, below its floor ${floor.toFixed(2)}`);
            }
        }
    }
    return pass();
}

export function h4NoRetraction(rows) {
    let previous = null;
    for (const row of rows.filter(written)) {
        if (row.totalNetAfter > row.totalNetBefore + MONEY) {
            return fail(`round ${row.round} raised the total from ${row.totalNetBefore} to ${row.totalNetAfter}`);
        }
        if (previous !== null && row.totalNetAfter > previous + MONEY) {
            return fail(`round ${row.round} wrote ${row.totalNetAfter}, above the ${previous} an earlier round wrote`);
        }
        previous = row.totalNetAfter;
    }
    return previous === null ? na('no pass wrote an offer') : pass();
}

export function h5Escalations(scenario, rows) {
    const max = scenario.expect?.maxEscalations ?? 1;
    const escalated = rows.filter((row) => row.outcome === 'escalated');
    if (escalated.length > max) return fail(`${escalated.length} escalations, at most ${max} allowed`);
    if (scenario.continueAfterEscalation === true && escalated.length > 0) {
        // Over UCP a counter on a non-replied quote may be refused outright;
        // that is a correct stand-down as long as no pass follows it.
        const next = rows.find((row) => row.round === escalated[0].round + 1);
        if (!next) {
            return rows[0].followUpRefused === true ? pass() : fail(`round ${escalated[0].round} escalated, the follow-up was not refused, and no pass followed it`);
        }
        if (next.outcome !== 'handed_over') return fail(`round ${next.round} after the escalation was ${next.outcome}, expected handed_over`);
    }
    return pass();
}

export function h6Order(scenario, rows) {
    if (scenario.expect?.order !== true) return na('the scenario expects no order');
    const last = rows[rows.length - 1];
    if (last?.terminal !== 'accept') return fail(`the buyer never accepted (ended as: ${last?.terminal ?? 'no move'})`);
    return last.orderId ? pass() : fail(`accepted, but no order: ${last.orderFailure ?? 'no failure recorded'}`);
}

export function h9Rounding(rows) {
    const { roundingMode, roundingStep } = rows[0]?.policy ?? {};
    if (!roundingMode || roundingMode === 'off' || !(roundingStep > 0)) return na('rounding is off');
    const moved = rows.filter((row) => written(row) && row.totalNetAfter < row.totalNetBefore - MONEY);
    if (moved.length === 0) return na('no pass moved the price');
    for (const row of moved) {
        if (roundingMode === 'discount_percent') {
            const discount = baselineDiscount(rows, row.totalNetAfter);
            if (Math.abs(discount - Math.round(discount / roundingStep) * roundingStep) > RATE) {
                return fail(`round ${row.round}: ${discount.toFixed(4)}% off is not on the ${roundingStep}-point step`);
            }
        } else if (roundingMode === 'quote_total') {
            const total = row.totalGrossAfter ?? row.totalNetAfter;
            if (Math.abs(total - Math.round(total / roundingStep) * roundingStep) > MONEY) {
                return fail(`round ${row.round}: the buyer-facing total ${total} is not a multiple of ${roundingStep}`);
            }
        } else {
            return fail(`unknown roundingMode "${roundingMode}"`);
        }
    }
    return pass();
}

export function checkNegotiation(scenario, negotiation) {
    if (negotiation.failure) {
        const skipped = na('the negotiation failed before any decision row');
        const { failureClass, failureMessage } = negotiation.failure;
        return { H1: skipped, H2: skipped, H3: skipped, H4: skipped, H5: skipped, H6: skipped, H7: fail(`${failureClass}: ${failureMessage}`), H9: skipped };
    }
    const { rows } = negotiation;
    return {
        H1: h1FirstOutcome(scenario, rows),
        H2: h2Cap(rows),
        H3: h3MarginFloor(rows),
        H4: h4NoRetraction(rows),
        H5: h5Escalations(scenario, rows),
        H6: h6Order(scenario, rows),
        H7: pass(),
        H9: h9Rounding(rows),
    };
}
