/**
 * Scenario files as the UCP eval buyer consumes them (spec
 * 2026-09-28-claude-code-evals-design, "Format additions"). Validation lives
 * here because this is the consumer of policy, buyer, counters,
 * continueAfterEscalation and purchasePriceRatio; PHP reads only
 * expect.firstOutcome (tests/Bench/ScenarioExpect.php).
 */
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

/** NegotiationOutcome's backing values -- the only vocabulary a row may carry. */
export const OUTCOMES = ['offered', 'countered', 'escalated', 'nothing_to_do', 'clarified', 'handed_over', 'acknowledged'];
/** QuoteLimits' own names, which are also the MerchantQuoteAgentPlugin.config.* keys. */
export const POLICY_KEYS = ['maxDiscountPercent', 'counterOfferMaxPercent', 'minMarginPercent', 'roundingMode', 'roundingStep'];
export const ROUNDING_MODES = ['off', 'discount_percent', 'quote_total'];
/** The generic profile tests/Integration/Bench/BenchRunTest.php used for every scenario. */
export const DEFAULT_BUYER = { targetDiscountPercent: 10, concessionRatio: 0.5 };
const BUYER_KEYS = Object.keys(DEFAULT_BUYER);
const PLACEHOLDER = /\{unit\*([0-9]+(?:\.[0-9]+)?)\}/g;
/** A tag is a short lowercase slug, so `EVAL_TAGS=floor` never misses a scenario tagged "Floor". */
const TAG = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;

const isNumber = (value) => typeof value === 'number' && Number.isFinite(value);

export function validateScenario(scenario) {
    const id = scenario?.id;
    const refuse = (message) => {
        throw new Error(`scenario ${id ?? '(no id)'}: ${message}`);
    };
    if (typeof id !== 'string' || id === '') refuse('"id" must be a non-empty string');
    if ('expectedBand' in scenario) refuse('"expectedBand" was replaced by "expect.firstOutcome"');
    if (!Array.isArray(scenario.tags) || scenario.tags.length === 0 || !scenario.tags.every((tag) => typeof tag === 'string' && TAG.test(tag))) {
        refuse('"tags" must be a non-empty list of lowercase slugs ("floor", "multi-round")');
    }
    if (!Array.isArray(scenario.lines) || scenario.lines.length === 0) refuse('"lines" must be a non-empty list');
    for (const line of scenario.lines) {
        if (!Number.isInteger(line.quantity) || line.quantity < 1) refuse('every line needs an integer quantity >= 1');
        if (line.purchasePriceRatio !== undefined && !(isNumber(line.purchasePriceRatio) && line.purchasePriceRatio > 0)) {
            refuse('"lines[].purchasePriceRatio" must be a number above zero');
        }
    }
    if (!Number.isInteger(scenario.maxRounds) || scenario.maxRounds < 1) refuse('"maxRounds" must be an integer >= 1');

    const outcomes = scenario.expect?.firstOutcome ?? [];
    if (outcomes.length === 0) refuse('"expect.firstOutcome" must name at least one NegotiationOutcome');
    for (const outcome of outcomes) {
        if (!OUTCOMES.includes(outcome)) refuse(`"expect.firstOutcome" names "${outcome}", not a NegotiationOutcome value (${OUTCOMES.join(', ')})`);
    }

    for (const [key, value] of Object.entries(scenario.policy ?? {})) {
        if (!POLICY_KEYS.includes(key)) refuse(`"policy.${key}" is not a setting a scenario may override (${POLICY_KEYS.join(', ')})`);
        const valid = key === 'roundingMode' ? ROUNDING_MODES.includes(value) : isNumber(value);
        if (!valid) refuse(`"policy.${key}" has an invalid value ${JSON.stringify(value)}`);
    }
    for (const key of Object.keys(scenario.buyer ?? {})) {
        if (!BUYER_KEYS.includes(key)) refuse(`"buyer.${key}" is not a scripted-buyer setting (${BUYER_KEYS.join(', ')})`);
    }

    const margin = scenario.policy?.minMarginPercent;
    const ratios = scenario.lines.map((line) => line.purchasePriceRatio).filter((ratio) => ratio !== undefined);
    if (margin !== undefined) {
        if (ratios.length === 0) refuse('"policy.minMarginPercent" needs a line with "purchasePriceRatio", or no floor applies');
        for (const ratio of ratios) {
            // MarginFloors caps the floor at today's price: such a scenario tests nothing.
            if (ratio * (1 + margin / 100) >= 1) refuse(`purchasePriceRatio ${ratio} with minMarginPercent ${margin} puts the floor at or above today's price`);
        }
    }
    if (scenario.continueAfterEscalation === true && !(scenario.counters?.length > 0)) {
        refuse('"continueAfterEscalation" needs a "counters" list: the follow-up posts its first entry');
    }
    return scenario;
}

export function loadScenarioDir(dir) {
    return readdirSync(dir).filter((name) => name.endsWith('.json')).sort()
        .map((name) => validateScenario(JSON.parse(readFileSync(join(dir, name), 'utf8'))));
}

/**
 * EVAL_TAGS: a comma-separated list; a scenario runs when it carries any of
 * them. Unset or empty runs everything. A tag no scenario carries is refused,
 * so a typo never quietly shrinks the run.
 */
export function selectByTags(scenarios, list) {
    const wanted = String(list ?? '').split(',').map((tag) => tag.trim()).filter((tag) => tag !== '');
    if (wanted.length === 0) return scenarios;
    const known = new Set(scenarios.flatMap((scenario) => scenario.tags));
    const unknown = wanted.filter((tag) => !known.has(tag));
    if (unknown.length > 0) throw new Error(`EVAL_TAGS names ${unknown.join(', ')}, which no scenario carries (known: ${[...known].sort().join(', ')})`);
    return scenarios.filter((scenario) => scenario.tags.some((tag) => wanted.includes(tag)));
}

/** `{unit*f}` -> f x unitPrice, two decimals; the same rule as tests/Bench/ScenarioAsk.php. */
export function render(text, unitPrice) {
    return text.replace(PLACEHOLDER, (_, factor) => (Math.round(Number(factor) * unitPrice * 100 + 1e-9) / 100).toFixed(2));
}

/**
 * The scripted buyer's next move -- tests/Bench/ScriptedBuyer.php's rules,
 * measured against the OPENING total every round. Patience is maxRounds, as
 * in PHP; the negotiation loop never sends a counter past the last round.
 */
export function buyerMove(scenario, { openingNet, currentNet, round }) {
    const { targetDiscountPercent, concessionRatio } = { ...DEFAULT_BUYER, ...scenario.buyer };
    const realized = ((openingNet - currentNet) / openingNet) * 100;
    // 0.01 pp: the shop cent-rounds totals, so "5% off" can land at 4.9998%.
    if (realized >= targetDiscountPercent - 0.01) return { kind: 'accept' };
    if (round > scenario.maxRounds) return { kind: 'walk' };
    const counters = scenario.counters ?? [];
    if (counters.length > 0) {
        const next = counters[round - 1];
        return next === undefined ? { kind: 'walk' } : { kind: 'counter', comment: next };
    }
    const ask = realized + (targetDiscountPercent - realized) * concessionRatio;
    return { kind: 'counter', comment: `That still leaves us short. Can you get to ${sprintfOneDecimal(ask)}% off?` };
}

/** PHP's sprintf('%.1f'): an exact tie (7.25) goes to even, where toFixed(1) rounds it away from zero. */
function sprintfOneDecimal(value) {
    const exact = value.toFixed(100).match(/^(-?\d+\.(\d))(\d*)$/);
    return exact && /^50*$/.test(exact[3]) && Number(exact[2]) % 2 === 0 ? exact[1] : value.toFixed(1);
}
