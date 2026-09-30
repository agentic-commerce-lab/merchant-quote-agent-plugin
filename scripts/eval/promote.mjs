#!/usr/bin/env node
/**
 * Turns one real quote's decision records into a draft scenario (spec
 * 2026-09-28-claude-code-evals-design, addendum 2026-09-29). Two plugin bugs
 * were each found from a single live quote; this makes such a quote a
 * regression scenario.
 *
 *   node scripts/eval/promote.mjs <quoteId|quoteNumber>               the draft on stdout
 *   node scripts/eval/promote.mjs <quoteId|quoteNumber> --write <id>  tests/Bench/scenarios/<id>.json
 *
 * Reads the Admin API only, as the eval integration may: decisions, their
 * quote_before and rounding traces, system_config and the product. The quote
 * entity itself is out of its reach, so everything comes from the rows.
 *
 * The draft has NO `expect`: what should have happened is a human's call. The
 * loader already refuses a scenario without `expect.firstOutcome`, so a draft
 * can never run (or pass quality:bench) unreviewed. What the quote did is in
 * the description and on stderr.
 */
import { existsSync, realpathSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { adminClient } from './admin.mjs';
import { POLICY_KEYS, render, validateScenario } from './scenarios.mjs';
import { EXPECTED_DEFAULTS, effectivePolicy, purchasePricesFor } from './settings.mjs';

const QUOTE_ID = /^[0-9a-f]{32}$/i;
const BASELINE = { ...EXPECTED_DEFAULTS, minMarginPercent: null, roundingMode: 'off', roundingStep: null };
/** A price in a comment: two decimals, not a percentage -- the figure checks.mjs askedPercents reads. */
const PRICE = /(\d+[.,]\d{2})(?!\d)(?!\s*%)/g;

/** Round 1's goods lines, from its quote_before trace: a line with a product and a positive price. */
export function openingLines(decisions, traces) {
    const lines = traces.find((t) => t.decisionId === decisions[0]?.id && t.kind === 'quote_before')?.content?.content?.lines;
    if (!Array.isArray(lines)) return null;
    return { all: lines, goods: lines.filter((line) => line.identity?.productId && line.unitPriceNet > 0) };
}

/** The unit price the buyer saw, as tests/Bench/ScenarioAsk.php forLine() derives it: the stored total over quantity. */
const buyerUnit = (line) => (line.totalInQuotePriceSpace ?? line.totalNet / (line.netRatio || 1)) / line.quantity;

/**
 * Each price at or below the unit price becomes `{unit*f}`, with the fewest
 * decimals of f that render back to the same cents, so the ask ports to the
 * eval product. A figure above the unit price (a total) stays as written.
 */
export function toPlaceholders(text, unit) {
    if (!(unit > 0)) return text;
    return text.replace(PRICE, (figure) => {
        const value = Number(figure.replace(',', '.'));
        if (!(value > 0) || value > unit + 0.005) return figure;
        for (let decimals = 2; decimals <= 6; decimals++) {
            const placeholder = `{unit*${Number((value / unit).toFixed(decimals))}}`;
            if (render(placeholder, unit) === value.toFixed(2)) return placeholder;
        }
        return figure;
    });
}

const observed = (decision) => `${decision.outcome}${decision.escalationReason ? ` (${decision.escalationReason})` : ''}`;

/**
 * The draft and what it could not carry over. `policy` is today's effective
 * shop policy; `purchaseNet` today's net purchase price of the first product,
 * or undefined.
 */
export function draftScenario({ id, decisions, traces, policy, purchaseNet }) {
    if (decisions.length === 0) throw new Error('the quote has no decision records');
    const first = decisions[0];
    const opening = openingLines(decisions, traces);
    if (!opening || opening.goods.length === 0) throw new Error(`round 1 (decision ${first.id}) has no quote_before trace with a priced product line`);
    const notes = ['"expect" is left out on purpose: decide what should happen, then add it -- until then the loader refuses the file'];
    const { all, goods } = opening;
    if (all.length > goods.length) notes.push(`${all.length - goods.length} line(s) without a product or a positive price (a discount line) were left out`);
    const products = new Set(goods.map((line) => line.identity.productId));
    if (products.size > 1) notes.push(`the quote has ${products.size} products; the eval prices every line as EVAL_PRODUCT_ID`);
    if (goods.some((line) => line.requestedUnitPrice != null)) notes.push('requestedUnitPrice is copied as an absolute price: it only means the same thing on the original product');
    const unit = buyerUnit(goods[0]);
    if (goods[0].netRatio === 1) notes.push('the quote was priced net (netRatio 1): {unit*f} renders in EVAL_TAX_STATUS, so run it with EVAL_TAX_STATUS=net');

    // Time-accurate where the rows say it: the cap from the decision, rounding from its trace. The rest is today's config.
    const effective = { ...policy, maxDiscountPercent: first.maxDiscountPercent ?? policy.maxDiscountPercent };
    const rounding = traces.find((t) => t.kind === 'rounding')?.meta;
    if (rounding) Object.assign(effective, { roundingMode: rounding.mode, roundingStep: rounding.step });
    notes.push(`counterOfferMaxPercent and minMarginPercent${rounding ? '' : ', roundingMode and roundingStep'} are today's shop settings, not necessarily the quote's`);
    const overrides = Object.fromEntries(POLICY_KEYS.filter((key) => effective[key] != null && effective[key] !== BASELINE[key]).map((key) => [key, effective[key]]));
    if (overrides.roundingMode === undefined) delete overrides.roundingStep;

    let ratio;
    if (overrides.minMarginPercent !== undefined) {
        if (purchaseNet > 0) {
            ratio = Number((purchaseNet / goods[0].unitPriceNet).toFixed(4));
            notes.push(`purchasePriceRatio ${ratio} is today's purchase price over the quote's net unit price`);
        } else {
            delete overrides.minMarginPercent;
            notes.push('the product has no purchase price today, so the margin floor was left out');
        }
    }

    const asks = decisions.slice(1).filter((d) => d.buyerAsk != null).map((d) => d.buyerAsk);
    if (decisions.length - 1 > asks.length) notes.push(`${decisions.length - 1 - asks.length} later round(s) read no buyer comment and were skipped`);
    const escalated = decisions.findIndex((d) => d.outcome === 'escalated');
    if (escalated >= 0 && escalated < decisions.length - 1) notes.push(`round ${escalated + 1} escalated: the scripted buyer stops there, so later counters do not replay (continueAfterEscalation posts only one follow-up)`);
    if (first.buyerAsk == null) notes.push('round 1 read no comment: openingAsk is empty');
    const openingAsk = toPlaceholders(first.buyerAsk ?? '', unit);
    const counters = asks.map((ask) => toPlaceholders(ask, unit));
    if ([first.buyerAsk ?? '', ...asks].join('\n') !== [openingAsk, ...counters].join('\n')) notes.push(`prices in the asks became {unit*f} against the quote's unit price ${unit.toFixed(2)}; check each one reads as meant`);

    const scenario = {
        id: id ?? `promoted-${first.quoteNumber ?? first.quoteId.slice(0, 8)}`,
        description: `Promoted from quote ${first.quoteNumber ?? '?'} (${first.quoteId}), ${String(first.createdAt).slice(0, 10)}. Observed: ${decisions.map(observed).join(' -> ')}. TODO: say what this proves, then add "expect".`,
        tags: ['promoted'],
        lines: goods.map((line, index) => ({
            productRef: 'any-purchasable',
            quantity: line.quantity,
            ...(line.requestedUnitPrice != null ? { requestedUnitPrice: line.requestedUnitPrice } : {}),
            ...(index === 0 && ratio !== undefined ? { purchasePriceRatio: ratio } : {}),
        })),
        openingAsk,
        persona: 'scripted:replay',
        maxRounds: 1 + counters.length,
        ...(Object.keys(overrides).length > 0 ? { policy: overrides } : {}),
        // An unreachable target: the buyer posts every recorded counter instead of accepting early.
        ...(counters.length > 0 ? { buyer: { targetDiscountPercent: 100 }, counters } : {}),
    };
    try {
        validateScenario({ ...scenario, expect: { firstOutcome: [first.outcome] } });
    } catch (error) {
        notes.push(`beyond "expect", the draft does not validate yet: ${error.message}`);
    }
    return { scenario, notes };
}

export async function promote(admin, ref, id) {
    const decisions = await admin.decisions(ref, QUOTE_ID.test(ref) ? 'quoteId' : 'quoteNumber');
    if (new Set(decisions.map((d) => d.quoteId)).size > 1) throw new Error(`quote number ${ref} matches several quotes: pass the quote id`);
    const traces = decisions.length > 0 ? await admin.traces(decisions.map((d) => d.id), ['quote_before', 'rounding']) : [];
    const salesChannelId = decisions[0]?.salesChannelId ?? null;
    const policy = effectivePolicy(await admin.configAt(null), salesChannelId ? await admin.configAt(salesChannelId) : {});
    const productId = openingLines(decisions, traces)?.goods[0]?.identity.productId;
    const product = policy.minMarginPercent != null && productId ? await admin.product(productId) : null;
    const purchaseNet = product ? purchasePricesFor(product, productId, policy)[productId] : undefined;
    return draftScenario({ id, decisions, traces, policy, purchaseNet });
}

async function main(args) {
    const [ref, flag, id] = args;
    if (!ref || (flag !== undefined && (flag !== '--write' || !id))) {
        console.error('usage: promote.mjs <quoteId|quoteNumber> [--write <scenarioId>]');
        process.exit(64);
    }
    // Same credentials as the eval buyer: `.env.eval`, with the environment winning.
    if (existsSync('.env.eval')) process.loadEnvFile('.env.eval');
    const { EVAL_ADMIN_CLIENT_ID: clientId, EVAL_ADMIN_CLIENT_SECRET: clientSecret } = process.env;
    if (!clientId || !clientSecret) {
        console.error('promote: EVAL_ADMIN_CLIENT_ID and EVAL_ADMIN_CLIENT_SECRET must be set');
        process.exit(64);
    }
    const shop = (process.env.EVAL_SHOP_URL || 'https://sw-ag.dev').replace(/\/$/, '');
    const { scenario, notes } = await promote(adminClient({ shop, clientId, clientSecret }), ref, id);
    const json = `${JSON.stringify(scenario, null, 4)}\n`;
    if (id) {
        const path = join('tests/Bench/scenarios', `${id}.json`);
        if (existsSync(path)) throw new Error(`${path} exists; pick another id`);
        writeFileSync(path, json);
        console.error(`promote: wrote ${path}`);
    } else {
        process.stdout.write(json);
    }
    for (const note of notes) console.error(`promote: ${note}`);
}

if (process.argv[1] && realpathSync(process.argv[1]) === fileURLToPath(import.meta.url)) {
    main(process.argv.slice(2)).catch((error) => {
        console.error(`promote: ${error.message}`);
        process.exit(2);
    });
}
