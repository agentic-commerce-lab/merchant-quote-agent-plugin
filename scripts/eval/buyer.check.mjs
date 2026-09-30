/**
 * Self-check for the UCP eval buyer (spec 2026-09-28-claude-code-evals-design,
 * "Testing"). No network: every HTTP call below goes to a fake fetch.
 *
 *     node scripts/eval/buyer.check.mjs
 */
import assert from 'node:assert/strict';
import { buyerMove, loadScenarioDir, render, selectByTags, validateScenario } from './scenarios.mjs';

const base = (over = {}) => ({
    id: 's', description: 'd', tags: ['band'], lines: [{ productRef: 'any-purchasable', quantity: 10 }], openingAsk: 'Could you do 5% off?',
    persona: 'scripted:moderate', maxRounds: 3, expect: { firstOutcome: ['offered'] }, ...over,
});
const refuses = (over, pattern) => assert.throws(() => validateScenario(base(over)), pattern);

// validation
validateScenario(base());
refuses({ expectedBand: 'auto' }, /expect\.firstOutcome/);
refuses({ expect: { firstOutcome: ['replied'] } }, /replied.*NegotiationOutcome/);
refuses({ expect: { firstOutcome: [] } }, /at least one/);
refuses({ policy: { maxDiscount: 10 } }, /policy\.maxDiscount\b/);
refuses({ policy: { roundingMode: 'nearest' } }, /roundingMode/);
refuses({ buyer: { patience: 3 } }, /buyer\.patience/);
refuses({ policy: { minMarginPercent: 15 } }, /purchasePriceRatio/);
refuses({ policy: { minMarginPercent: 20 }, lines: [{ productRef: 'any-purchasable', quantity: 1, purchasePriceRatio: 0.9 }] }, /at or above/);
refuses({ continueAfterEscalation: true }, /counters/);
assert.equal(loadScenarioDir('tests/Bench/scenarios').length >= 10, true, 'the shipped scenarios validate');
refuses({ tags: undefined }, /"tags"/);
refuses({ tags: [] }, /"tags"/);
refuses({ tags: [''] }, /"tags"/);
refuses({ tags: ['Floor'] }, /"tags"/);
refuses({ tags: 'floor' }, /"tags"/);
validateScenario(base({ tags: ['multi-round', 'floor'] }));

// EVAL_TAGS -- any listed tag selects a scenario; a tag nobody carries is refused, not an empty run
const tagged = [base({ id: 'a', tags: ['floor'] }), base({ id: 'b', tags: ['rounding', 'gross'] }), base({ id: 'c', tags: ['band'] })];
assert.deepEqual(selectByTags(tagged, 'floor,rounding').map((s) => s.id), ['a', 'b']);
assert.deepEqual(selectByTags(tagged, ' gross ').map((s) => s.id), ['b']);
assert.equal(selectByTags(tagged, undefined).length, 3);
assert.equal(selectByTags(tagged, '').length, 3);
assert.throws(() => selectByTags(tagged, 'floor,flor'), /EVAL_TAGS names flor, which no scenario carries/);

// placeholders
assert.equal(render('Can you get to {unit*0.89} a unit?', 80), 'Can you get to 71.20 a unit?');
assert.equal(render('{unit*0.9} or {unit*0.8}', 50), '45.00 or 40.00');
assert.equal(render('Could you do 5% off?', 80), 'Could you do 5% off?');

// the scripted buyer -- same rules as tests/Bench/ScriptedBuyer.php
assert.deepEqual(buyerMove(base(), { openingNet: 1000, currentNet: 890, round: 1 }), { kind: 'accept' }); // 11% >= 10% default
assert.deepEqual(buyerMove(base(), { openingNet: 1000, currentNet: 960, round: 1 }), { kind: 'counter', comment: 'That still leaves us short. Can you get to 7.0% off?' });
// 4.5% realized asks 7.25%: PHP's sprintf('%.1f') rounds that exact tie to even, toFixed would print 7.3
assert.deepEqual(buyerMove(base(), { openingNet: 1000, currentNet: 955, round: 1 }), { kind: 'counter', comment: 'That still leaves us short. Can you get to 7.2% off?' });
assert.deepEqual(buyerMove(base({ buyer: { targetDiscountPercent: 5 } }), { openingNet: 1000, currentNet: 950, round: 1 }), { kind: 'accept' });
// the shop cent-rounds: "5% off" of 2181.68 lands at 2072.60, 4.9998% -- still the 5% the buyer asked for
assert.deepEqual(buyerMove(base({ buyer: { targetDiscountPercent: 5 } }), { openingNet: 2181.68, currentNet: 2072.6, round: 1 }), { kind: 'accept' });
assert.equal(buyerMove(base({ buyer: { targetDiscountPercent: 5 } }), { openingNet: 1000, currentNet: 951, round: 1 }).kind, 'counter');
const retreat = base({ buyer: { targetDiscountPercent: 50 }, counters: ['8% would work.'] });
assert.deepEqual(buyerMove(retreat, { openingNet: 1000, currentNet: 880, round: 1 }), { kind: 'counter', comment: '8% would work.' });
assert.deepEqual(buyerMove(retreat, { openingNet: 1000, currentNet: 880, round: 2 }), { kind: 'walk' });

import { createPublicKey, verify } from 'node:crypto';
import { mkdtempSync, readFileSync as readFile, statSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join as joinPath } from 'node:path';
import { canonicalUri, loadOrCreateKey, profileDocument, signHeaders, signatureBase, tokenStore } from './ucp.mjs';

// the vectors from ucp-quote-agent.py --selftest
assert.equal(canonicalUri('https://x/a?b=2&a=1'), 'https://x/a?a=1&b=2');
assert.equal(canonicalUri('https://x/a?s=a:b/c'), 'https://x/a?s=a%3Ab%2Fc');
assert.equal(canonicalUri('https://x/a?s=x y'), 'https://x/a?s=x%20y');
assert.equal(canonicalUri('https://x/a?s=-._~'), 'https://x/a?s=-._~');
assert.equal(canonicalUri('https://x/ucp/quotes'), 'https://x/ucp/quotes');
assert.equal(canonicalUri('https://x/a#frag'), 'https://x/a');
assert.deepEqual(signatureBase('POST', 'https://x/ucp/quotes', 'sha-256=:abc:', '("@method");created=1').split('\n'), [
    '"@method": POST', '"@target-uri": https://x/ucp/quotes', '"content-digest": sha-256=:abc:', '"@signature-params": ("@method");created=1',
]);

// a signature the port produces verifies as DER (the PHP SDK's openssl_verify)
const keyDir = mkdtempSync(joinPath(tmpdir(), 'eval-key-'));
const { privateKey, jwk } = loadOrCreateKey(joinPath(keyDir, 'key.pem'));
assert.equal(statSync(joinPath(keyDir, 'key.pem')).mode & 0o777, 0o600);
assert.equal(loadOrCreateKey(joinPath(keyDir, 'key.pem')).jwk.x, jwk.x, 'the key is created once and reused');
const headers = signHeaders(privateKey, 'POST', 'https://x/ucp/quotes', Buffer.from('{}'), 1_700_000_000_000);
const params = headers['Signature-Input'].slice('sig='.length);
assert.match(params, /created=1700000000;expires=1700000120;keyid="eval-buyer";alg="ES256"/);
const der = Buffer.from(headers.Signature.slice('sig=:'.length, -1), 'base64');
assert.ok(verify('sha256', Buffer.from(signatureBase('POST', 'https://x/ucp/quotes', headers['Content-Digest'], params)), createPublicKey(privateKey), der));
assert.deepEqual(Object.keys(profileDocument({ q: {} }, jwk)).sort(), ['signing_keys', 'ucp']);

// Review Focus 5 -- a rotated refresh token is on disk before the access token is used
const tokenFile = joinPath(keyDir, 'token.json');
const calls = [];
const fakeFetch = async (url, init) => {
    calls.push({ url, body: String(init.body) });
    return new Response(JSON.stringify({ access_token: 'a2', refresh_token: 'r2', expires_in: 3600 }), { status: 200 });
};
const store = tokenStore({ file: tokenFile, tokenEndpoint: 'https://shop/token', profileUri: 'https://p/.well-known/ucp', privateKey, fetchImpl: fakeFetch });
store.save({ access_token: 'a1', refresh_token: 'r1', expires_in: -1 });
assert.equal(await store.accessToken(), 'a2');
assert.match(calls[0].body, /grant_type=refresh_token/);
assert.match(calls[0].body, /refresh_token=r1/);
assert.equal(JSON.parse(readFile(tokenFile, 'utf8')).refreshToken, 'r2');
assert.equal(statSync(tokenFile).mode & 0o777, 0o600);

// concurrent lanes on an expired grant share one refresh: a second POST would present the revoked r2
calls.length = 0;
store.save({ access_token: 'a2', refresh_token: 'r2', expires_in: -1 });
assert.deepEqual(await Promise.all([1, 2, 3, 4].map(() => store.accessToken())), ['a2', 'a2', 'a2', 'a2']);
assert.equal(calls.length, 1, 'exactly one refresh for four concurrent callers');

import { adminClient } from './admin.mjs';
import { buildRows } from './rows.mjs';
import { effectivePolicy, planSettings, purchasePricesFor, writablePrices } from './settings.mjs';

// the Admin API client -- one token for many calls, criteria in the body
const adminCalls = [];
const adminFetch = async (url, init = {}) => {
    adminCalls.push({ url, method: init.method ?? 'GET', body: init.body ? JSON.parse(init.body) : null });
    if (url.endsWith('/api/oauth/token')) return new Response(JSON.stringify({ access_token: 'adm', expires_in: 600 }));
    return new Response(JSON.stringify({ total: 1, data: [{ id: 'd1', quoteId: 'q1', outcome: 'offered' }] }));
};
const admin = adminClient({ shop: 'https://shop', clientId: 'id', clientSecret: 'secret', fetchImpl: adminFetch });
assert.deepEqual((await admin.decisions('q1')).map((d) => d.id), ['d1']);
await admin.traces(['d1']);
assert.equal(adminCalls.filter((c) => c.url.endsWith('/api/oauth/token')).length, 1, 'the admin token is reused');
const decisionSearch = adminCalls.find((c) => c.url.endsWith('/api/search/merchant-quote-agent-decision'));
assert.deepEqual(decisionSearch.body.filter, [{ type: 'equals', field: 'quoteId', value: 'q1' }]);
assert.deepEqual(decisionSearch.body.sort, [{ field: 'createdAt', order: 'ASC' }]);

// rows -- the exact JSONL shape the checker reads
const snapshotLines = [{ identity: { lineItemId: 'l1', productId: 'p1' }, quantity: 10, unitPriceNet: 9.5, totalNet: 95, netRatio: 1 }];
const rows = buildRows({
    runId: 'r', scenarioId: 's', rep: 2,
    decisions: [
        { id: 'd1', outcome: 'offered', band: 'grant', escalationReason: null, discountPercentGranted: 5, maxDiscountPercent: 15, totalNetBefore: 100, totalNetAfter: 95, totalGrossBefore: 119, totalGrossAfter: 113.05, replyToBuyer: 'r1', buyerAsk: 'a1', model: 'm', promptTokens: 1, completionTokens: 2, strategyVersionId: 'v', createdAt: '2026-09-28T10:00:00.000+00:00' },
        { id: 'd2', outcome: 'escalated', totalNetBefore: 95, totalNetAfter: null, createdAt: '2026-09-28T10:01:00.000+00:00' },
    ],
    traces: [
        { decisionId: 'd1', kind: 'quote_before', content: { content: { lines: [{ ...snapshotLines[0], unitPriceNet: 10, totalNet: 100 }] } } },
        { decisionId: 'd1', kind: 'quote_after', content: { content: { lines: snapshotLines } } },
        { decisionId: 'd2', kind: 'quote_before', content: { content: { lines: snapshotLines } } },
    ],
    policy: { maxDiscountPercent: 15, counterOfferMaxPercent: 25, minMarginPercent: null, roundingMode: 'off', roundingStep: null },
    purchasePricesNet: {}, terminal: 'walk', orderId: null, orderFailure: null, followUpRefused: false,
});
assert.deepEqual(rows.map((r) => [r.round, r.rep, r.decisionId]), [[1, 2, 'd1'], [2, 2, 'd2']]);
assert.deepEqual(rows[0].linesAfter, [{ lineItemId: 'l1', productId: 'p1', quantity: 10, unitPriceNet: 9.5, totalNet: 95, netRatio: 1 }]);
assert.equal(rows[1].linesAfter, null, 'no quote_after: null, never []');
assert.equal(rows[0].replyToBuyer, 'r1');
assert.equal(rows[1].terminal, 'walk');
assert.equal(rows[0].buyerLatencyMs, null, 'no latencies given: null, never 0');
assert.equal(buildRows({ decisions: [{ id: 'd1', durationMs: 900 }], traces: [], latencies: { d1: 5000 } })[0].buyerLatencyMs, 5000);
assert.equal(buildRows({ decisions: [{ id: 'd1', durationMs: 900 }], traces: [] })[0].durationMs, 900, "the shop's own pass duration");

// settings -- effective value, the write scope, and a restore that deletes what was unset (Review Focus 4)
const g = { 'MerchantQuoteAgentPlugin.config.maxDiscountPercent': 15, 'MerchantQuoteAgentPlugin.config.counterOfferMaxPercent': 25 };
const c = { 'MerchantQuoteAgentPlugin.config.counterOfferMaxPercent': 30 };
assert.equal(effectivePolicy(g, c).counterOfferMaxPercent, 30);
assert.equal(effectivePolicy(g, c).minMarginPercent, null);
assert.equal(effectivePolicy(g, {}).roundingMode, 'off');
const plan = planSettings({ globalValues: g, channelValues: c, overrides: { maxDiscountPercent: 0, counterOfferMaxPercent: 20, minMarginPercent: 15 } });
assert.deepEqual(plan.writes, [
    { scope: 'global', key: 'MerchantQuoteAgentPlugin.config.maxDiscountPercent', value: 0 },
    { scope: 'channel', key: 'MerchantQuoteAgentPlugin.config.counterOfferMaxPercent', value: 20 },
    { scope: 'global', key: 'MerchantQuoteAgentPlugin.config.minMarginPercent', value: 15 },
]);
assert.deepEqual(plan.restore, [
    { scope: 'global', key: 'MerchantQuoteAgentPlugin.config.maxDiscountPercent', value: 15 },
    { scope: 'channel', key: 'MerchantQuoteAgentPlugin.config.counterOfferMaxPercent', value: 30 },
    { scope: 'global', key: 'MerchantQuoteAgentPlugin.config.minMarginPercent', value: null },
]);

import { negotiate, pool } from './negotiate.mjs';

/**
 * A fake shop: each POST that should trigger a pass appends the next scripted
 * decision; GET returns the quote with the net total that decision wrote.
 */
function fakeShop(passes, { refuseCounterUnlessReplied = true, counterStatus = null, quotedUnitPrice = 119, getStatus = 200 } = {}) {
    const decisions = [];
    const posted = [];
    let state = 'open';
    let net = 1000;
    const pass = () => {
        const next = passes[decisions.length];
        if (!next) return;
        decisions.push({ id: `d${decisions.length + 1}`, createdAt: String(decisions.length), totalNetBefore: net, ...next });
        if (next.totalNetAfter != null) net = next.totalNetAfter;
        state = next.outcome === 'offered' || next.outcome === 'countered' || next.outcome === 'acknowledged' ? 'replied' : 'open';
    };
    const quote = () => ({ id: 'q1', state, totals: { net, gross: net * 1.19, tax_status: 'gross' }, line_items: [{ unit_price: quotedUnitPrice }] });
    const ucp = {
        async request(method, path, { json } = {}) {
            posted.push({ method, path, json });
            if (method === 'POST' && path === '/ucp/quotes') { pass(); return { status: 201, body: quote() }; }
            if (method === 'GET') return getStatus === 200 ? { status: 200, body: quote() } : { status: getStatus, body: { error: 'unavailable '.repeat(100) } };
            if (path.endsWith('/counter')) {
                if (counterStatus) return { status: counterStatus, body: { error: 'upstream '.repeat(100) } };
                if (refuseCounterUnlessReplied && state !== 'replied') return { status: 400, body: { error: 'not replied' } };
                pass();
                return { status: 200, body: quote() };
            }
            if (path.endsWith('/accept')) { state = 'accepted'; return { status: 200, body: { ...quote(), order: { id: 'o1' } } }; }
            if (path.endsWith('/decline')) {
                // quote.openapi.json: decline is "Valid only in state `replied`"
                if (state !== 'replied') return { status: 400, body: { error: 'not replied' } };
                state = 'declined';
                return { status: 200, body: quote() };
            }
            return { status: 404, body: {} };
        },
    };
    const admin = { decisions: async () => decisions, traces: async () => [] };
    return { ucp, admin, posted };
}
const policy0 = { maxDiscountPercent: 15, counterOfferMaxPercent: 25, minMarginPercent: null, roundingMode: 'off', roundingStep: null };
const policyArgs = () => ({ rep: 1, runId: 'r', policy: policy0, purchasePricesNet: {}, unitPrice: 119, productId: 'p1', timeouts: { pass: 1, standDown: 0.1, poll: 0.01 }, sleep: () => Promise.resolve() });
const run = (scenario, shop) => negotiate({ ...shop, ...policyArgs(), scenario: validateScenario(scenario) });

// accept: 5% granted, buyer targets 5% -> accept -> order
const accepted = await run(base({ buyer: { targetDiscountPercent: 5 } }), fakeShop([{ outcome: 'offered', totalNetAfter: 950 }]));
assert.deepEqual(accepted.map((r) => [r.round, r.outcome, r.terminal, r.orderId, r.cleanup]), [[1, 'offered', 'accept', 'o1', 'accepted']]);

// latency: from sending the message to the poll that saw its decision, per round
let clock = 0;
const timedShop = fakeShop([{ outcome: 'offered', totalNetAfter: 980 }, { outcome: 'offered', totalNetAfter: 970 }]);
const timed = await negotiate({
    ...policyArgs(),
    ucp: { request: async (...args) => { clock += 1000; return timedShop.ucp.request(...args); } },
    admin: { ...timedShop.admin, decisions: async () => { clock += 2500; return timedShop.admin.decisions(); } },
    scenario: validateScenario(base({ maxRounds: 2 })),
    now: () => clock,
});
assert.deepEqual(timed.map((r) => r.buyerLatencyMs), [3500, 3500]);
// a refused follow-up's stand-down wait answers no message: only round 1 is timed
const untimed = await run(base({ counters: ['Any news?'], continueAfterEscalation: true }), fakeShop([{ outcome: 'escalated', totalNetAfter: null }]));
assert.equal(typeof untimed[0].buyerLatencyMs, 'number');

// counter then walk at maxRounds, never a counter past the last round
const walkShop = fakeShop([{ outcome: 'offered', totalNetAfter: 980 }, { outcome: 'offered', totalNetAfter: 970 }]);
const walked = await run(base({ maxRounds: 2 }), walkShop);
assert.equal(walked.length, 2);
assert.equal(walkShop.posted.filter((p) => p.path.endsWith('/counter')).length, 1);
assert.ok(walkShop.posted.some((p) => p.path.endsWith('/decline')), 'an unsettled quote is declined at the end');
assert.deepEqual(walked.map((r) => r.cleanup), ['declined', 'declined']);

// placeholders render against the product's unit price, inside the create request
const rendered = fakeShop([{ outcome: 'offered', totalNetAfter: 950 }]);
await run(base({ openingAsk: '{unit*0.85} including tax', buyer: { targetDiscountPercent: 5 } }), rendered);
assert.equal(rendered.posted[0].json.comment, '101.15 including tax');
assert.deepEqual(rendered.posted[0].json.line_items, [{ product_id: 'p1', quantity: 10 }]);
// the placeholder rendered from the admin price, but the quote carries another: a failure row naming both
const repriced = await run(base({ openingAsk: '{unit*0.85} including tax' }), fakeShop([{ outcome: 'offered', totalNetAfter: 950 }], { quotedUnitPrice: 120 }));
assert.equal(repriced.length, 1);
assert.equal(repriced[0].cellFailure, true);
assert.match(repriced[0].failureMessage, /120/);
assert.match(repriced[0].failureMessage, /119/);
// an empty openingAsk sends no comment at all (structured-only)
const silentAsk = fakeShop([{ outcome: 'escalated', totalNetAfter: null }]);
await run(base({ openingAsk: '' }), silentAsk);
assert.equal('comment' in silentAsk.posted[0].json, false);

// escalation ends the loop, and the quote is declined
const escalatedShop = fakeShop([{ outcome: 'escalated', totalNetAfter: null }]);
const escalated = await run(base(), escalatedShop);
assert.deepEqual(escalated.map((r) => r.outcome), ['escalated']);
assert.ok(escalatedShop.posted.some((p) => p.path.endsWith('/decline')));
assert.equal(escalated[0].cleanup, 'left_open', 'UCP refuses a decline outside replied: counted, not hidden');

// continueAfterEscalation, refused follow-up: recorded, no second row
const refused = await run(base({ counters: ['Any news?'], continueAfterEscalation: true }), fakeShop([{ outcome: 'escalated', totalNetAfter: null }]));
assert.equal(refused.length, 1);
assert.equal(refused[0].followUpRefused, true);
assert.equal(refused[0].cleanup, 'left_open');

// a 5xx follow-up is the shop failing, not standing down: a failure row
const brokenFollowUp = await run(base({ counters: ['Any news?'], continueAfterEscalation: true }), fakeShop([{ outcome: 'escalated', totalNetAfter: null }], { counterStatus: 503 }));
assert.equal(brokenFollowUp.length, 1);
assert.equal(brokenFollowUp[0].cellFailure, true);
assert.match(brokenFollowUp[0].failureMessage, /HTTP 503/);
assert.ok(brokenFollowUp[0].failureMessage.length < 400, 'the body is truncated');
assert.equal(brokenFollowUp[0].followUpRefused, undefined);
assert.equal(brokenFollowUp[0].cleanup, 'left_open');

// continueAfterEscalation, accepted follow-up: the next row is what the shop recorded
const handedOver = await run(base({ counters: ['Any news?'], continueAfterEscalation: true }), fakeShop([{ outcome: 'escalated', totalNetAfter: null }, { outcome: 'handed_over', totalNetAfter: null }], { refuseCounterUnlessReplied: false }));
assert.deepEqual(handedOver.map((r) => r.outcome), ['escalated', 'handed_over']);
assert.deepEqual(handedOver.map((r) => r.cleanup), ['left_open', 'left_open']);

// a failing quote GET is the shop failing, not "the buyer cannot move": a failure row
const brokenGet = await run(base(), fakeShop([{ outcome: 'offered', totalNetAfter: 980 }], { getStatus: 503 }));
assert.equal(brokenGet.length, 1);
assert.equal(brokenGet[0].cellFailure, true);
assert.match(brokenGet[0].failureMessage, /HTTP 503/);
assert.ok(brokenGet[0].failureMessage.length < 400, 'the body is truncated');

// a pass that never comes is a failure row, not a hang
const timedOut = await run(base(), fakeShop([]));
assert.equal(timedOut.length, 1);
assert.equal(timedOut[0].cellFailure, true);
assert.equal(timedOut[0].failureClass, 'PassTimeout');
assert.equal(timedOut[0].cleanup, 'left_open', 'a failure row records its cleanup too');

// pool keeps at most `limit` workers in flight and keeps order
let inFlight = 0;
let peak = 0;
const pooled = await pool([1, 2, 3, 4, 5], 2, async (n) => { inFlight++; peak = Math.max(peak, inFlight); await new Promise((r) => setTimeout(r, 5)); inFlight--; return n * 2; });
assert.deepEqual(pooled, [2, 4, 6, 8, 10]);
assert.equal(peak, 2);

// a shop-wide margin floor: H3 checks against the product's own purchase price (a shop may run one)
const eur = 'b7d2554b0ce847cd82f3ac9bd1c0dfca';
const shopProduct = { price: [{ currencyId: eur, net: 727.23, gross: 865.4 }], purchasePrices: [{ extensions: [], currencyId: 'usd', net: 999, gross: 999 }, { extensions: [], currencyId: eur, net: 231.85, gross: 275.9, linked: true, listPrice: null, percentage: null, regulationPrice: null, apiAlias: 'price' }] };
assert.deepEqual(purchasePricesFor(shopProduct, 'p1', { minMarginPercent: 10 }), { p1: 231.85 }, 'the price currency, not the first entry');
assert.deepEqual(purchasePricesFor(shopProduct, 'p1', { minMarginPercent: null }), {}, 'no floor, nothing to check');
assert.deepEqual(purchasePricesFor({ price: shopProduct.price }, 'p1', { minMarginPercent: 10 }), {}, 'no purchase price, no floor applies');
// an Admin API read carries read-only fields the DAL refuses on write
assert.deepEqual(writablePrices(shopProduct.purchasePrices.slice(1)), [{ currencyId: eur, net: 231.85, gross: 275.9, linked: true, listPrice: null, percentage: null, regulationPrice: null }]);
assert.equal(writablePrices(null), null);

console.log('buyer: ok');
