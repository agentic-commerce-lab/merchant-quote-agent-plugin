/**
 * One negotiation's decision + trace records -> the JSONL rows stage 2 reads
 * (spec "JSONL rows"). Pure. `round` is the row's position in the quote's
 * decision list. `latencies` maps a decision id to the milliseconds the buyer
 * waited for it, from sending its message to seeing the row.
 */
const DECISION_FIELDS = [
    'outcome', 'band', 'escalationReason', 'discountPercentGranted', 'maxDiscountPercent', 'totalNetBefore', 'totalNetAfter',
    'totalGrossBefore', 'totalGrossAfter', 'replyToBuyer', 'buyerAsk', 'model', 'promptTokens', 'completionTokens',
    'durationMs', 'strategyVersionId', 'createdAt',
];

/** A trace's `content` is the whole Bridge\Data\QuoteSnapshot; its lines are content.content.lines[]. */
function lines(traces, decisionId, kind) {
    const trace = traces.find((t) => t.decisionId === decisionId && t.kind === kind);
    const found = trace?.content?.content?.lines;
    if (!Array.isArray(found)) return null;
    return found.map((line) => ({
        lineItemId: line.identity?.lineItemId ?? null,
        productId: line.identity?.productId ?? null,
        quantity: line.quantity ?? null,
        unitPriceNet: line.unitPriceNet ?? null,
        totalNet: line.totalNet ?? null,
        netRatio: line.netRatio ?? 1,
    }));
}

export function buildRows({ runId, scenarioId, rep, decisions, traces, policy, purchasePricesNet, terminal, orderId, orderFailure, followUpRefused, latencies = {} }) {
    return decisions.map((decision, index) => ({
        runId,
        scenarioId,
        rep,
        round: index + 1,
        decisionId: decision.id,
        ...Object.fromEntries(DECISION_FIELDS.map((field) => [field, decision[field] ?? null])),
        buyerLatencyMs: latencies[decision.id] ?? null, // null: a pass the buyer did not wait for
        linesBefore: lines(traces, decision.id, 'quote_before'),
        linesAfter: lines(traces, decision.id, 'quote_after'),
        policy,
        purchasePricesNet,
        terminal,
        orderId,
        orderFailure,
        followUpRefused,
    }));
}
