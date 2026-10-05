/**
 * The dashboard's four success measures, as pure functions over the folded
 * quote list, the raw passes, and the quote/order rows.
 *
 * Separate from decision.ts, which is already at the house file-length target:
 * folding these in would push it well past 600 lines. This module reads the
 * fields decision.ts's foldToQuotes() already computed (`.acted`,
 * `.escalated`, `.netBefore`, `.latestAnswered`, `.disposition`) rather than
 * importing any of its functions, so nothing here depends on decision.ts and
 * nothing flows back either way.
 *
 * Every measure returns `null` rather than `0` when it has nothing to measure.
 * That distinction is the whole point on this page: a merchant without
 * `quote:read` must see a figure absent, not a confident zero, and so must a
 * period with no deals in it.
 */

interface Deal {
    quoteId: string;
    amountNet: number;
    submittedAt: string | null;
    confirmedAt: string | null;
    agentDiscount: number | null;
    baselineDiscount: number | null;
}

interface ValueRange {
    min: number;
    max: number;
}

/**
 * Share of the quotes the agent acted on that it handled without ever asking
 * a human.
 *
 * The denominator is every quote the agent acted on in the period
 * (`.acted`: a pass offered, countered, clarified, acknowledged or escalated,
 * or was drafted for review). A quote whose every pass was `nothing_to_do` or
 * `handed_over` was never automated, and counting it inflated the rate with
 * work the agent did not do (QA-03). Concluded or not makes no difference:
 * restricting the denominator to concluded negotiations would drop unresolved
 * escalations out of it, so a shop with ten quotes stuck in the review queue
 * would report 100% auto-execution — a worse failure than counting a
 * still-open, never-escalated quote as auto-executed.
 */
export function autoExecutionRate(quotes: any[]): { rate: number | null; escalated: number; total: number } {
    const acted = quotes.filter((quote) => quote.acted);
    const total = acted.length;
    const escalated = acted.filter((quote) => quote.escalated).length;

    return {
        rate: total > 0 ? ((total - escalated) / total) * 100 : null,
        escalated,
        total,
    };
}

/**
 * How long the deal desk took, over the escalations that have been resolved.
 *
 * Mean rather than median because the merchant-facing wording is "average",
 * and a median over the handful of escalations a period produces is not more
 * informative.
 *
 * `open` is reported alongside so a backlog reads as a backlog. It matters at
 * release: escalations raised before the resolved_at migration have no
 * resolution and would otherwise silently vanish from the measure.
 */
export function escalationResolution(
    passes: any[],
    slaHours: number | null,
): { meanMs: number | null; measured: number; open: number; withinSla: number | null } {
    const escalations = passes.filter((pass) => pass.outcome === 'escalated');
    const durations = escalations
        .map((pass) => span(pass.createdAt, pass.resolvedAt))
        .filter((ms): ms is number => ms !== null);
    const slaMs = typeof slaHours === 'number' && slaHours > 0 ? slaHours * 3600000 : null;

    return {
        meanMs: mean(durations),
        measured: durations.length,
        open: escalations.length - durations.length,
        withinSla: slaMs === null ? null : durations.filter((ms) => ms <= slaMs).length,
    };
}

/**
 * Splits the period's accepted quotes into the agent's and the baseline's,
 * with one definition of every figure so the two sides are comparable.
 *
 * `foldedByQuoteId` decides which side a quote is on: a quote with servicing
 * passes is the agent's, one without is the baseline — "quotes the agent never
 * touched", which is the only pre-agent comparison this shop can measure
 * without a segment model.
 */
export function splitDeals(
    quoteRows: any[],
    orderDates: Map<string, string>,
    foldedByQuoteId: Map<string, any>,
): { agent: Deal[]; baseline: Deal[] } {
    const agent: Deal[] = [];
    const baseline: Deal[] = [];

    (quoteRows ?? []).forEach((row) => {
        const folded = foldedByQuoteId.get(row.id) ?? null;

        const deal: Deal = {
            quoteId: row.id,
            amountNet: Number(row.amountNet ?? 0),
            // `requestedAt` is the true RFQ submission time and is the better
            // number, but it does not exist on SwagCommercial 7.12 — read
            // only, never filtered on, so its absence is `undefined` rather
            // than a DAL error.
            submittedAt: row.requestedAt ?? row.createdAt ?? null,
            confirmedAt: (row.orderId && orderDates.get(row.orderId)) || null,
            agentDiscount: folded === null ? null : agentDiscountPercent(folded),
            baselineDiscount: baselineDiscountPercent(row),
        };

        (folded === null ? baseline : agent).push(deal);
    });

    return { agent, baseline };
}

/**
 * What the agent gave away, from our own records, so it does not depend on
 * which SwagCommercial version wrote the quote.
 *
 * Null when the quote has no answered pass — a quote that escalated, was
 * answered by a human and then accepted has no agent-set price, and this
 * measure is the discount on AGENT-negotiated deals.
 *
 * Known limitation, inherited from QuoteBaselineLines rather than introduced
 * here: `netBefore` is the quote's stored total, unadjusted for a quantity
 * reduction or a line removal, so a buyer who halves a quantity shrinks the
 * total structurally and it reads as a concession.
 */
function agentDiscountPercent(quote: any): number | null {
    const original = Number(quote.netBefore ?? 0);
    const pass: unknown = quote.latestAnswered;
    const record = typeof pass === 'object' && pass !== null ? pass as Record<string, unknown> : null;
    const changes = record?.sentChanges;
    const sentNet = record?.reviewStatus === 'sent' && typeof changes === 'object' && changes !== null
        ? (changes as Record<string, unknown>).totalNet
        : null;
    const realized = typeof sentNet === 'number' && Number.isFinite(sentNet) ? sentNet : record?.totalNetAfter;

    if (!(original > 0) || realized === null || realized === undefined) {
        return null;
    }

    return ((original - Number(realized)) / original) * 100;
}

/**
 * What a human gave away, from the quote's own discount totals.
 *
 * `totalLineItemDiscount` does not exist on SwagCommercial 7.12, so a merchant
 * there who negotiates by editing line prices rather than setting a quote
 * discount reads as 0%. That understates the baseline and so makes the agent
 * look worse, which is the safe direction.
 */
function baselineDiscountPercent(row: any): number | null {
    const given = Number(row.totalDiscount ?? 0) + Number(row.totalLineItemDiscount ?? 0);
    const original = Number(row.amountNet ?? 0) + given;

    return original > 0 ? (given / original) * 100 : null;
}

/**
 * The net-value band of the agent's deals, which is the "equivalent deal
 * sizes" control.
 *
 * Both "equivalent deal sizes" and "the same account segment" need a segment
 * this shop does not model, and net value is the one comparable dimension
 * available. Read off `amountNet` on both sides — never off the decision
 * record's pre-negotiation `netBefore`, which would bias the baseline upward
 * by exactly the discount being measured.
 */
export function valueRange(deals: Deal[]): ValueRange | null {
    const values = deals.map((deal) => deal.amountNet).filter((value) => Number.isFinite(value) && value > 0);

    if (values.length === 0) {
        return null;
    }

    return { min: Math.min(...values), max: Math.max(...values) };
}

/** No range means nothing comparable, not "compare against everything". */
export function withinRange(deals: Deal[], range: ValueRange | null): Deal[] {
    if (range === null) {
        return [];
    }

    return deals.filter((deal) => deal.amountNet >= range.min && deal.amountNet <= range.max);
}

/**
 * Discount granted on agent-negotiated deals against the same figure on deals
 * the agent never touched, in the same value band.
 *
 * Deliberately not called margin: margin needs COGS, which neither this plugin
 * nor a typical B2B catalog carries. This is the original price against the
 * price sold.
 */
export function priceRetention(
    agent: Deal[],
    baseline: Deal[],
): { agentDiscount: number | null; baselineDiscount: number | null; comparable: number } {
    const comparable = withinRange(baseline, valueRange(agent));

    return {
        agentDiscount: mean(agent.map((deal) => deal.agentDiscount)),
        baselineDiscount: mean(comparable.map((deal) => deal.baselineDiscount)),
        comparable: comparable.length,
    };
}

/** RFQ submission to confirmed order, both sides, same value band. */
export function dealCycleTime(
    agent: Deal[],
    baseline: Deal[],
): { agentMs: number | null; baselineMs: number | null; comparable: number } {
    const comparable = withinRange(baseline, valueRange(agent));

    return {
        agentMs: mean(agent.map(cycleMs)),
        baselineMs: mean(comparable.map(cycleMs)),
        comparable: comparable.length,
    };
}

function cycleMs(deal: Deal): number | null {
    return span(deal.submittedAt, deal.confirmedAt);
}

/**
 * Milliseconds between two instants, or null if either is missing or the pair
 * is backwards. A negative span is corrupt data, not a negative duration.
 */
function span(from: string | null | undefined, to: string | null | undefined): number | null {
    if (!from || !to) {
        return null;
    }

    const ms = Date.parse(to) - Date.parse(from);

    return Number.isFinite(ms) && ms >= 0 ? ms : null;
}

/** Null-tolerant mean. Null in, null out — never 0, which would read as measured. */
function mean(values: (number | null | undefined)[]): number | null {
    const numbers = values.filter((value): value is number => typeof value === 'number' && Number.isFinite(value));

    if (numbers.length === 0) {
        return null;
    }

    return numbers.reduce((sum, value) => sum + value, 0) / numbers.length;
}

/**
 * What one negotiation cost in tokens, averaged over the negotiations we could
 * measure.
 *
 * Takes raw passes rather than folded quotes because `foldToQuotes()` keeps
 * only `latest` and `latestAnswered`, discarding the token counts on every
 * other round — and a negotiation's cost is what all its rounds cost together.
 * `escalationResolution` takes raw passes for a comparable reason.
 *
 * Not every OpenAI-compatible provider sends a `usage` block, so a pass can be
 * unmeasured. An unmeasured pass contributes nothing to its quote's sum; a
 * quote whose passes are ALL unmeasured is dropped entirely rather than
 * reported as having cost zero.
 *
 * Deliberately not money: the plugin records `model` and `modelHost` but
 * carries no price table, and a currency figure derived from rates we do not
 * have would be invented.
 */
export function tokensPerNegotiation(passes: any[]): {
    meanTokens: number | null;
    measured: number;
    quotes: number;
} {
    const byQuote = new Map<string, number>();
    let measured = 0;

    (passes ?? []).forEach((pass) => {
        const promptKnown = typeof pass.promptTokens === 'number' && Number.isFinite(pass.promptTokens);
        const completionKnown = typeof pass.completionTokens === 'number' && Number.isFinite(pass.completionTokens);

        if (!promptKnown && !completionKnown) {
            return;
        }

        measured += 1;
        const tokens = (promptKnown ? pass.promptTokens : 0) + (completionKnown ? pass.completionTokens : 0);
        byQuote.set(pass.quoteId, (byQuote.get(pass.quoteId) ?? 0) + tokens);
    });

    return {
        meanTokens: mean([...byQuote.values()]),
        measured,
        quotes: byQuote.size,
    };
}

/**
 * A duration at the scale a merchant reads it. `formatDuration` in decision.ts
 * is for a single pass and tops out at seconds; these spans are hours and
 * days.
 */
export function formatSpan(ms: number | null): string {
    if (ms === null || ms === undefined) {
        return '–';
    }

    if (ms < 3600000) {
        return `${Math.round(ms / 60000)} min`;
    }

    if (ms < 86400000) {
        return `${(ms / 3600000).toFixed(1)} h`;
    }

    return `${(ms / 86400000).toFixed(1)} d`;
}
