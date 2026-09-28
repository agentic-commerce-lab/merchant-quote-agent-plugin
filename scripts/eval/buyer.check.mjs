/**
 * Self-check for the UCP eval buyer (spec 2026-09-28-claude-code-evals-design,
 * "Testing"). No network: every HTTP call below goes to a fake fetch.
 *
 *     node scripts/eval/buyer.check.mjs
 */
import assert from 'node:assert/strict';
import { buyerMove, loadScenarioDir, render, validateScenario } from './scenarios.mjs';

const base = (over = {}) => ({
    id: 's', description: 'd', lines: [{ productRef: 'any-purchasable', quantity: 10 }], openingAsk: 'Could you do 5% off?',
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
import { effectivePolicy, planSettings } from './settings.mjs';

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

console.log('buyer: ok');
