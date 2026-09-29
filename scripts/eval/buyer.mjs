#!/usr/bin/env node
/**
 * Stage 1 of the eval: an external UCP buyer against the deployed shop (spec
 * "Stage 1 — the UCP buyer"). No SSH, no code on the shop.
 *
 *   node scripts/eval/buyer.mjs setup              one-time: key, profile, browser consent
 *   node scripts/eval/buyer.mjs preflight          free checks; exit 64 on the first failure
 *   node scripts/eval/buyer.mjs run <runDir>       phase A + phase B -> runs.jsonl, run.json
 *   node scripts/eval/buyer.mjs restore <runDir>   replay restore.json after a hard crash
 */
import { spawn } from 'node:child_process';
import { appendFileSync, existsSync, readFileSync, writeFileSync } from 'node:fs';
import { basename, join } from 'node:path';
import { adminClient } from './admin.mjs';
import { negotiate, pool } from './negotiate.mjs';
import { loadScenarioDir } from './scenarios.mjs';
import { applyWrites, effectivePolicy, planSettings } from './settings.mjs';
import { consent, loadOrCreateKey, startProfileServer, startTunnel, tokenStore, ucpClient } from './ucp.mjs';

const BUYER_DIR = 'var/eval/.buyer';
const env = (name, fallback) => {
    const value = process.env[name] ?? fallback;
    if (value === undefined || value === '') throw new UsageError(`${name} must be set`);
    return value;
};
/** Exit 64: nothing was written to the shop yet. Any later abort exits 2; 1 belongs to the verdict. */
class UsageError extends Error {}

async function beforeShopWrites(work) {
    try {
        return await work();
    } catch (error) {
        throw error instanceof UsageError ? error : new UsageError(error.message, { cause: error });
    }
}

const shopUrl = () => env('EVAL_SHOP_URL', 'https://sw-ag.dev').replace(/\/$/, '');
const adminFromEnv = () => adminClient({ shop: shopUrl(), clientId: env('EVAL_ADMIN_CLIENT_ID'), clientSecret: env('EVAL_ADMIN_CLIENT_SECRET') });

function context() {
    const shop = shopUrl();
    const domain = env('EVAL_NGROK_DOMAIN');
    const profileUri = `https://${domain}/.well-known/ucp`;
    const { privateKey, jwk } = loadOrCreateKey(join(BUYER_DIR, 'key.pem'));
    const tokenFile = join(BUYER_DIR, 'token.json');
    const tokenEndpoint = existsSync(join(BUYER_DIR, 'oauth.json')) ? JSON.parse(readFileSync(join(BUYER_DIR, 'oauth.json'), 'utf8')).tokenEndpoint : null;
    const tokens = tokenStore({ file: tokenFile, tokenEndpoint, profileUri, privateKey });
    let admin;
    return {
        shop, domain, profileUri, privateKey, jwk, tokens,
        port: Number(env('EVAL_PROFILE_PORT', '8787')),
        ucp: ucpClient({ shop, profileUri, privateKey, tokens }),
        // lazy: setup never reads the Admin API, so it does not need the integration credentials
        get admin() {
            return (admin ??= adminFromEnv());
        },
    };
}

async function shopCapabilities(shop) {
    const profile = await (await fetch(`${shop}/.well-known/ucp`)).json();
    return (profile.ucp ?? profile).capabilities ?? {};
}

/** The profile has to be up for every signed request: the shop fetches it to verify. */
async function withProfile(ctx, work) {
    const { server, tunnel } = await beforeShopWrites(async () => {
        const started = startProfileServer({ port: ctx.port, capabilities: await shopCapabilities(ctx.shop), jwk: ctx.jwk });
        try {
            await started.listening;
            return { server: started, tunnel: await startTunnel({ domain: ctx.domain, port: ctx.port }) };
        } catch (error) {
            started.close();
            throw error;
        }
    });
    try {
        return await work(server);
    } finally {
        tunnel.close();
        server.close();
    }
}

async function setup() {
    const ctx = context();
    await withProfile(ctx, async (server) => {
        console.log('setup: opening the shop\'s consent page -- sign in as the eval customer (needs QUOTE_MANAGEMENT)');
        const { grant, tokenEndpoint } = await consent({
            shop: ctx.shop, profileUri: ctx.profileUri, redirectUri: `https://${ctx.domain}/callback`, privateKey: ctx.privateKey,
            callback: server.callback, openUrl: (url) => spawn('open', [url], { stdio: 'ignore' }),
        });
        writeFileSync(join(BUYER_DIR, 'oauth.json'), `${JSON.stringify({ tokenEndpoint })}\n`, { mode: 0o600 });
        tokenStore({ file: join(BUYER_DIR, 'token.json'), tokenEndpoint, profileUri: ctx.profileUri, privateKey: ctx.privateKey }).save(grant);
        console.log('setup: the buyer grant is stored in var/eval/.buyer/ (git-ignored, 0600)');
    });
}

const EXPECTED_DEFAULTS = { maxDiscountPercent: 15, counterOfferMaxPercent: 25, minMarginPercent: null, roundingMode: 'off' };

async function shopState(ctx) {
    const salesChannelId = await ctx.admin.salesChannelFor(ctx.shop);
    const globalValues = await ctx.admin.configAt(null);
    const channelValues = await ctx.admin.configAt(salesChannelId);
    const value = (key) => channelValues[`MerchantQuoteAgentPlugin.config.${key}`] ?? globalValues[`MerchantQuoteAgentPlugin.config.${key}`];
    return { salesChannelId, globalValues, channelValues, value, policy: effectivePolicy(globalValues, channelValues) };
}

const preflight = (ctx) => beforeShopWrites(() => checks(ctx));

async function checks(ctx) {
    const state = await shopState(ctx);
    if (state.value('enabled') !== true) throw new UsageError('the quote agent is not enabled on the shop');
    if (state.value('draftMode') === true) throw new UsageError('the shop runs in Draft Mode: replies are never sent');
    if (state.value('notifyBuyerOnEscalation') === false) throw new UsageError('notifyBuyerOnEscalation is off: escalations would be silent');
    for (const [key, expected] of Object.entries(EXPECTED_DEFAULTS)) {
        if ((state.policy[key] ?? null) !== expected) throw new UsageError(`shop ${key} is ${JSON.stringify(state.policy[key])}; the scenario set assumes ${JSON.stringify(expected)}`);
    }
    const product = await ctx.admin.product(env('EVAL_PRODUCT_ID'));
    if (!product) throw new UsageError(`EVAL_PRODUCT_ID ${process.env.EVAL_PRODUCT_ID} is not a product on the shop`);
    if (!product.price?.[0]) throw new UsageError(`EVAL_PRODUCT_ID ${product.id} has no own price (a variant inheriting it?); pick a simple product`);
    await ctx.tokens.accessToken();
    loadScenarioDir('tests/Bench/scenarios');
    return { ...state, product, pluginVersion: await ctx.admin.pluginVersion(), model: state.value('llmModel') ?? null };
}

/** The product's price in the quote's price space: gross, unless the shop quotes net. */
function unitPriceOf(product, taxStatus) {
    const price = product.price?.[0];
    return taxStatus === 'net' ? price.net : price.gross;
}

async function run(runDir) {
    const ctx = context();
    await withProfile(ctx, async () => {
        const { state, scenarios } = await beforeShopWrites(async () => ({ state: await preflight(ctx), scenarios: loadScenarioDir(join(runDir, 'scenarios')) }));
        const reps = Number(env('EVAL_REPS', '3'));
        const timeouts = { pass: Number(env('EVAL_PASS_TIMEOUT', '180')), standDown: Number(env('EVAL_STANDDOWN_WAIT', '60')), poll: 5 };
        const productId = env('EVAL_PRODUCT_ID');
        const taxStatus = env('EVAL_TAX_STATUS', 'gross');
        const unitPrice = unitPriceOf(state.product, taxStatus);
        const runId = basename(runDir);
        writeFileSync(join(runDir, 'run.json'), `${JSON.stringify({ runId, shop: ctx.shop, reps, pluginVersion: state.pluginVersion, model: state.model, salesChannelId: state.salesChannelId, productId, judgeModel: process.env.EVAL_JUDGE_MODEL ?? 'sonnet' }, null, 2)}\n`);
        const out = join(runDir, 'runs.jsonl');
        const write = (rows) => rows.forEach((row) => appendFileSync(out, `${JSON.stringify(row)}\n`));
        let leftOpen = 0;
        const cells = (list) => list.flatMap((scenario) => Array.from({ length: reps }, (_, i) => ({ scenario, rep: i + 1 })));
        const play = (policy, purchasePricesNet) => async ({ scenario, rep }) => {
            const rows = await negotiate({ ucp: ctx.ucp, admin: ctx.admin, scenario, rep, runId, policy, purchasePricesNet, unitPrice, productId, timeouts });
            write(rows);
            if (rows[0]?.cleanup === 'left_open') leftOpen++;
            console.log(`${scenario.id} #${rep}: ${rows.map((r) => r.outcome ?? r.failureClass).join(' -> ')}`);
        };

        // Phase A: the shop's own settings, in parallel.
        await pool(cells(scenarios.filter((s) => !s.policy)), Number(env('EVAL_PARALLEL', '4')), play(state.policy, {}));

        // Phase B: one settings scenario at a time, restore file first.
        for (const scenario of scenarios.filter((s) => s.policy)) {
            await withSettings(ctx, runDir, scenario, async (policy, purchasePricesNet) => {
                await pool(cells([scenario]), reps, play(policy, purchasePricesNet));
            });
        }
        console.log(`${leftOpen} quote(s) left open on the shop: UCP declines only a replied quote`);
    });
}

async function withSettings(ctx, runDir, scenario, work) {
    // Fresh, not the preflight snapshot: a merchant edit during phase A must survive the restore.
    const state = await shopState(ctx);
    const productId = env('EVAL_PRODUCT_ID');
    const product = await ctx.admin.product(productId);
    const { writes, restore } = planSettings({ globalValues: state.globalValues, channelValues: state.channelValues, overrides: scenario.policy });
    const ratio = scenario.lines.find((l) => l.purchasePriceRatio !== undefined)?.purchasePriceRatio;
    const restoreFile = join(runDir, 'restore.json');
    writeFileSync(restoreFile, `${JSON.stringify({ salesChannelId: state.salesChannelId, config: restore, productId, purchasePrices: product.purchasePrices ?? null, touchesProduct: ratio !== undefined })}\n`);
    const undo = () => replayRestore(ctx, restoreFile);
    const onSignal = () => undo().finally(() => process.exit(130));
    process.once('SIGINT', onSignal);
    process.once('SIGTERM', onSignal);
    try {
        await applyWrites(ctx.admin, state.salesChannelId, writes);
        let purchasePricesNet = {};
        if (ratio !== undefined) {
            const price = product.price[0];
            const net = Math.round(ratio * price.net * 100) / 100;
            await ctx.admin.writePurchasePrices(productId, [{ currencyId: price.currencyId, net, gross: Math.round(net * (price.gross / price.net) * 100) / 100, linked: false }]);
            purchasePricesNet = { [productId]: net };
        }
        const after = await shopState(ctx);
        for (const [key, value] of Object.entries(scenario.policy)) {
            if (after.policy[key] !== value) throw new Error(`${key} did not take effect: shop reads ${JSON.stringify(after.policy[key])}`);
        }
        await work(after.policy, purchasePricesNet);
    } finally {
        process.removeListener('SIGINT', onSignal);
        process.removeListener('SIGTERM', onSignal);
        await undo();
    }
}

async function replayRestore(ctx, restoreFile) {
    const saved = JSON.parse(readFileSync(restoreFile, 'utf8'));
    await applyWrites(ctx.admin, saved.salesChannelId, saved.config);
    if (saved.touchesProduct) await ctx.admin.writePurchasePrices(saved.productId, saved.purchasePrices);
    console.log(`restored ${saved.config.length} setting(s)${saved.touchesProduct ? ' and the purchase price' : ''}`);
}

const [verb, runDir] = process.argv.slice(2);
const verbs = {
    setup,
    preflight: async () => {
        const ctx = context();
        await withProfile(ctx, async () => {
            const state = await preflight(ctx);
            console.log(`preflight: ok -- plugin ${state.pluginVersion}, model ${state.model}, sales channel ${state.salesChannelId}`);
        });
    },
    run: () => run(runDir),
    restore: () => replayRestore({ admin: adminFromEnv() }, join(runDir, 'restore.json')),
};
if (!verbs[verb] || ((verb === 'run' || verb === 'restore') && !runDir)) {
    console.error('usage: buyer.mjs <setup|preflight|run <runDir>|restore <runDir>>');
    process.exit(64);
}
// .then: a verb that throws synchronously (a missing env var) still lands in .catch
Promise.resolve().then(() => verbs[verb]()).catch((error) => {
    console.error(`${verb}: ${error.message}`);
    process.exit(error instanceof UsageError ? 64 : 2);
});
