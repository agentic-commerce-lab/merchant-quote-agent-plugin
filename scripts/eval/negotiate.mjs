/**
 * One scenario x rep, played over UCP (spec "One negotiation"). Every
 * outcome writes a decision row, so "the pass is done" means the quote's
 * decision count grew; a pass that never comes is a PassTimeout failure row.
 * The quote is declined at the end unless the buyer accepted, so an eval run
 * does not pile escalations into the merchant's queue.
 */
import { buildRows } from './rows.mjs';
import { buyerMove, render } from './scenarios.mjs';

class PassTimeout extends Error {}

const defaultSleep = (seconds) => new Promise((resolve) => setTimeout(resolve, seconds * 1000));

async function waitForDecision(admin, quoteId, seen, { timeoutSeconds, pollSeconds, sleep }) {
    const deadline = Date.now() + timeoutSeconds * 1000;
    for (;;) {
        const decisions = await admin.decisions(quoteId);
        if (decisions.length > seen) return decisions;
        if (Date.now() >= deadline) return null;
        await sleep(pollSeconds);
    }
}

export async function negotiate({ ucp, admin, scenario, rep, runId, policy, purchasePricesNet, unitPrice, timeouts, sleep = defaultSleep, productId }) {
    const wait = (quoteId, seen, timeoutSeconds) => waitForDecision(admin, quoteId, seen, { timeoutSeconds, pollSeconds: timeouts.poll ?? 5, sleep });
    let quoteId = null;
    let terminal = null;
    let order = { orderId: null, orderFailure: null };
    let followUpRefused = false;
    try {
        const lineItems = scenario.lines.map((line) => ({
            product_id: productId,
            quantity: line.quantity,
            ...(line.requestedUnitPrice !== undefined ? { requested_unit_price: line.requestedUnitPrice } : {}),
        }));
        const opening = scenario.openingAsk === '' ? undefined : render(scenario.openingAsk, unitPrice);
        const created = await ucp.request('POST', '/ucp/quotes', { json: { line_items: lineItems, ...(opening ? { comment: opening } : {}) } });
        if (created.status !== 201) throw new Error(`the RFQ was refused: HTTP ${created.status} ${JSON.stringify(created.body).slice(0, 300)}`);
        quoteId = created.body.id;
        let continued = false;

        let decisions = await wait(quoteId, 0, timeouts.pass);
        if (!decisions) throw new PassTimeout(`no decision for round 1 within ${timeouts.pass} s`);
        // The quote before the agent touched it: the create response may already carry the first pass.
        const openingNet = decisions[0].totalNetBefore ?? created.body.totals.net;
        for (let round = 1; ; round++) {
            const last = decisions[decisions.length - 1];
            if (last.outcome === 'escalated' && scenario.continueAfterEscalation === true && !continued) {
                continued = true;
                const seen = decisions.length;
                const follow = await ucp.request('POST', `/ucp/quotes/${quoteId}/counter`, { json: { comment: render(scenario.counters[0], unitPrice) } });
                if (follow.status >= 400) {
                    followUpRefused = true;
                    decisions = (await wait(quoteId, seen, timeouts.standDown)) ?? decisions;
                    break;
                }
                decisions = await wait(quoteId, seen, timeouts.pass);
                if (!decisions) throw new PassTimeout(`no decision after the follow-up within ${timeouts.pass} s`);
                break;
            }
            const quote = (await ucp.request('GET', `/ucp/quotes/${quoteId}`)).body;
            if (quote.state !== 'replied') break; // escalated, clarified, handed over: the buyer cannot move
            const move = buyerMove(scenario, { openingNet, currentNet: quote.totals.net, round });
            if (move.kind === 'accept') {
                const accepted = await ucp.request('POST', `/ucp/quotes/${quoteId}/accept`);
                terminal = 'accept';
                order = accepted.status === 200 ? { orderId: accepted.body.order?.id ?? null, orderFailure: null } : { orderId: null, orderFailure: `HTTP ${accepted.status} ${JSON.stringify(accepted.body).slice(0, 300)}` };
                break;
            }
            if (move.kind === 'walk' || round >= scenario.maxRounds) {
                terminal = 'walk';
                break;
            }
            const seen = decisions.length; // counted before the POST: a pass may land while it is in flight
            const countered = await ucp.request('POST', `/ucp/quotes/${quoteId}/counter`, { json: { comment: render(move.comment, unitPrice) } });
            if (countered.status >= 400) throw new Error(`the counter was refused: HTTP ${countered.status} ${JSON.stringify(countered.body).slice(0, 300)}`);
            decisions = await wait(quoteId, seen, timeouts.pass);
            if (!decisions) throw new PassTimeout(`no decision for round ${round + 1} within ${timeouts.pass} s`);
        }

        if (terminal !== 'accept') await ucp.request('POST', `/ucp/quotes/${quoteId}/decline`, { json: {} });
        const all = await admin.decisions(quoteId);
        const traces = all.length > 0 ? await admin.traces(all.map((d) => d.id)) : [];
        return buildRows({ runId, scenarioId: scenario.id, rep, decisions: all, traces, policy, purchasePricesNet, terminal, ...order, followUpRefused });
    } catch (error) {
        if (quoteId) await ucp.request('POST', `/ucp/quotes/${quoteId}/decline`, { json: {} }).catch(() => {});
        return [{ runId, scenarioId: scenario.id, rep, cellFailure: true, failureClass: error instanceof PassTimeout ? 'PassTimeout' : error.constructor.name, failureMessage: error.message, quoteId }];
    }
}

/** At most `limit` workers in flight; results in input order. */
export async function pool(items, limit, worker) {
    const results = new Array(items.length);
    let next = 0;
    const lanes = Array.from({ length: Math.min(limit, items.length) }, async () => {
        while (next < items.length) {
            const index = next++;
            results[index] = await worker(items[index], index);
        }
    });
    await Promise.all(lanes);
    return results;
}
