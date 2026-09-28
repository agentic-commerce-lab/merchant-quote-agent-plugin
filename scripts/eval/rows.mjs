/**
 * One negotiation's decision + trace records -> the JSONL rows stage 2 reads
 * (spec "JSONL rows"). Pure. `round` is the row's position in the quote's
 * decision list.
 */
const DECISION_FIELDS = [
    'outcome', 'band', 'escalationReason', 'discountPercentGranted', 'maxDiscountPercent', 'totalNetBefore', 'totalNetAfter',
    'totalGrossBefore', 'totalGrossAfter', 'replyToBuyer', 'buyerAsk', 'model', 'promptTokens', 'completionTokens',
    'strategyVersionId', 'createdAt',
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

export function buildRows({ runId, scenarioId, rep, decisions, traces, policy, purchasePricesNet, terminal, orderId, orderFailure, followUpRefused }) {
    return decisions.map((decision, index) => ({
        runId,
        scenarioId,
        rep,
        round: index + 1,
        decisionId: decision.id,
        ...Object.fromEntries(DECISION_FIELDS.map((field) => [field, decision[field] ?? null])),
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
