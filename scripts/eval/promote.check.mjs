/**
 * Self-check for promote.mjs: a recorded quote becomes a draft scenario the
 * loader refuses until a human adds `expect`. No network: the Admin API
 * client runs against a fake fetch.
 *
 *     node scripts/eval/promote.check.mjs
 */
import assert from 'node:assert/strict';
import { adminClient } from './admin.mjs';
import { draftScenario, promote, toPlaceholders } from './promote.mjs';
import { validateScenario } from './scenarios.mjs';

// placeholders -- the fewest decimals that render back to the same cents
assert.equal(toPlaceholders('735.59 including tax and we have a deal.', 865.4), '{unit*0.85} including tax and we have a deal.');
assert.equal(toPlaceholders('Then 700.00 and we are done.', 865.4), 'Then {unit*0.80887} and we are done.');
assert.equal(toPlaceholders('735,59 incl. tax', 865.4), '{unit*0.85} incl. tax', 'a decimal comma');
assert.equal(toPlaceholders('A total of 6000.00 works.', 865.4), 'A total of 6000.00 works.', 'a figure above the unit price is a total: left as written');
assert.equal(toPlaceholders('Could you do 12.50% off, net 60?', 865.4), 'Could you do 12.50% off, net 60?', 'percentages and plain numbers are not prices');

// a recorded quote, the shape the Admin API returns (quote 1101's prices)
const eur = 'b7d2554b0ce847cd82f3ac9bd1c0dfca';
const quoteId = 'a'.repeat(32);
const line = { identity: { lineItemId: 'l1', productId: 'p1' }, quantity: 1, unitPriceNet: 727.23, totalNet: 727.23, netRatio: 0.8403362606886989, totalInQuotePriceSpace: 865.4 };
const discountLine = { identity: { lineItemId: 'd', productId: null }, quantity: 1, unitPriceNet: -10, totalNet: -10, netRatio: 1 };
const decisions = [
    { id: 'd1', quoteId, quoteNumber: '1101', salesChannelId: 'sc', createdAt: '2026-09-20T10:00:00.000+00:00', outcome: 'countered', escalationReason: null, maxDiscountPercent: 15, buyerAsk: '735.59 including tax and we have a deal.' },
    { id: 'd2', quoteId, quoteNumber: '1101', salesChannelId: 'sc', createdAt: '2026-09-20T10:05:00.000+00:00', outcome: 'nothing_to_do', escalationReason: null, maxDiscountPercent: 15, buyerAsk: null },
    { id: 'd3', quoteId, quoteNumber: '1101', salesChannelId: 'sc', createdAt: '2026-09-20T10:09:00.000+00:00', outcome: 'escalated', escalationReason: 'no_further_concession', maxDiscountPercent: 15, buyerAsk: 'Then 700.00 and we are done.' },
];
const traces = [
    { decisionId: 'd1', kind: 'quote_before', meta: { lineCount: 2 }, content: { content: { lines: [line, discountLine] } } },
    { decisionId: 'd1', kind: 'rounding', meta: { mode: 'discount_percent', step: 0.5, unrounded: 12.2, rounded: 12, skipped: null }, content: null },
];
const calls = [];
const fakeFetch = async (url, init = {}) => {
    const body = init.body ? JSON.parse(init.body) : null;
    calls.push({ url, body });
    const data = (rows) => new Response(JSON.stringify({ total: rows.length, data: rows }));
    if (url.endsWith('/api/oauth/token')) return new Response(JSON.stringify({ access_token: 'adm', expires_in: 600 }));
    if (url.endsWith('/api/search/merchant-quote-agent-decision')) return data(decisions);
    if (url.endsWith('/api/search/merchant-quote-agent-trace')) return data(traces);
    if (url.endsWith('/api/search/product')) return data([{ id: 'p1', price: [{ currencyId: eur, net: 727.23, gross: 865.4 }], purchasePrices: [{ currencyId: eur, net: 231.85, gross: 275.9 }] }]);
    if (url.includes('salesChannelId=sc')) return new Response(JSON.stringify({ 'MerchantQuoteAgentPlugin.config.roundingMode': 'off' }));
    if (url.includes('/api/_action/system-config')) {
        return new Response(JSON.stringify({ 'MerchantQuoteAgentPlugin.config.maxDiscountPercent': 15, 'MerchantQuoteAgentPlugin.config.counterOfferMaxPercent': 25, 'MerchantQuoteAgentPlugin.config.minMarginPercent': 10 }));
    }
    return new Response('{}', { status: 404 });
};
const admin = adminClient({ shop: 'https://shop', clientId: 'id', clientSecret: 'secret', fetchImpl: fakeFetch });

const { scenario, notes } = await promote(admin, '1101');
const decisionSearch = calls.find((c) => c.url.endsWith('/api/search/merchant-quote-agent-decision'));
assert.deepEqual(decisionSearch.body.filter, [{ type: 'equals', field: 'quoteNumber', value: '1101' }], 'a quote number searches quoteNumber');
assert.deepEqual(calls.find((c) => c.url.endsWith('/api/search/merchant-quote-agent-trace')).body.filter[1].value, ['quote_before', 'rounding']);
assert.deepEqual(scenario, {
    id: 'promoted-1101',
    description: `Promoted from quote 1101 (${quoteId}), 2026-09-20. Observed: countered -> nothing_to_do -> escalated (no_further_concession). TODO: say what this proves, then add "expect".`,
    tags: ['promoted'],
    lines: [{ productRef: 'any-purchasable', quantity: 1, purchasePriceRatio: 0.3188 }],
    openingAsk: '{unit*0.85} including tax and we have a deal.',
    persona: 'scripted:replay',
    maxRounds: 2,
    // the rounding trace wins over today's channel setting (off); the floor is today's 10%
    policy: { minMarginPercent: 10, roundingMode: 'discount_percent', roundingStep: 0.5 },
    buyer: { targetDiscountPercent: 100 },
    counters: ['Then {unit*0.80887} and we are done.'],
});
assert.ok(notes.some((n) => /"expect" is left out/.test(n)));
assert.ok(notes.some((n) => /1 line\(s\) without a product/.test(n)));
assert.ok(notes.some((n) => /1 later round\(s\) read no buyer comment/.test(n)));
assert.ok(notes.some((n) => /today's shop settings/.test(n)));
assert.ok(!notes.some((n) => /does not validate yet/.test(n)), 'apart from expect, the draft is a valid scenario');

// the draft can never run unreviewed; with a human's expect it loads
assert.throws(() => validateScenario(scenario), /expect\.firstOutcome/);
validateScenario({ ...scenario, expect: { firstOutcome: ['countered', 'offered'] } });

// a quote id searches quoteId; the shop's own defaults produce no policy block
calls.length = 0;
await promote(admin, quoteId, 'my-id');
assert.deepEqual(calls.find((c) => c.url.endsWith('/api/search/merchant-quote-agent-decision')).body.filter, [{ type: 'equals', field: 'quoteId', value: quoteId }]);
const plain = draftScenario({ decisions: [decisions[0]], traces: [traces[0]], policy: { maxDiscountPercent: 15, counterOfferMaxPercent: 25, minMarginPercent: null, roundingMode: 'off', roundingStep: 0.5 } });
assert.equal('policy' in plain.scenario, false, 'the eval defaults, and a step without a mode, override nothing');
assert.equal('counters' in plain.scenario, false);
assert.equal(plain.scenario.maxRounds, 1);
// a floor without a purchase price is left out, never a scenario the loader refuses for it
const noPurchase = draftScenario({ decisions: [{ ...decisions[0], maxDiscountPercent: 12 }], traces: [traces[0]], policy: { maxDiscountPercent: 15, counterOfferMaxPercent: 25, minMarginPercent: 10, roundingMode: 'off', roundingStep: null } });
assert.deepEqual(noPurchase.scenario.policy, { maxDiscountPercent: 12 }, "the cap comes from the decision row, not today's config");
assert.ok(noPurchase.notes.some((n) => /no purchase price/.test(n)));

// what cannot be drafted fails loudly
assert.throws(() => draftScenario({ decisions: [], traces: [], policy: {} }), /no decision records/);
assert.throws(() => draftScenario({ decisions: [decisions[0]], traces: [], policy: {} }), /no quote_before trace/);
const twoQuotes = { ...admin, decisions: async () => [decisions[0], { ...decisions[1], quoteId: 'b'.repeat(32) }] };
await assert.rejects(promote(twoQuotes, '1101'), /several quotes/);

console.log('promote: ok');
