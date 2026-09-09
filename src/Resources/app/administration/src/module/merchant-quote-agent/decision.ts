/**
 * The decision vocabulary and formatters both pages read.
 *
 * Lives here rather than in either page because the two pages have to agree:
 * when the list says a pass answered the buyer, the detail page has to show
 * that pass's offer, and both used to decide that separately against a value
 * the backend no longer writes.
 *
 * `outcome` values are NegotiationOutcome's. `replied` is not one of them — it
 * is data written before that enum existed, mapped here so old rows still
 * label themselves instead of showing a raw column value.
 *
 * `variant` values are Meteor's badge/banner set: neutral, info, positive,
 * critical, attention. `success` is not one of them and renders unstyled.
 */

const OUTCOME_VARIANTS: Record<string, string> = {
    offered: 'positive',
    countered: 'positive',
    replied: 'positive',
    clarified: 'info',
    escalated: 'critical',
    nothing_to_do: 'neutral',
};

const BAND_VARIANTS: Record<string, string> = {
    grant: 'positive',
    counter: 'info',
    escalate: 'critical',
};

/**
 * The outcomes that put an offer in front of the buyer, so carry a price and a
 * message worth rendering. Mirrors NegotiationOutcome::answeredTheBuyer(),
 * plus the legacy value.
 */
export const ANSWERED_OUTCOMES = ['offered', 'countered', 'replied'];

export function answeredTheBuyer(outcome: string | null): boolean {
    return outcome !== null && ANSWERED_OUTCOMES.includes(outcome);
}

/**
 * Where a quote stands, from its most recent pass.
 *
 * These classes partition the serviced quotes — each quote is in exactly one —
 * which is the point. Counting "quotes that were ever answered" and "quotes
 * that ever escalated" put the same quote in both and produced shares summing
 * past 100%.
 *
 * An outcome nobody here knows about becomes `other` rather than folding into
 * `noAction`: a silent default is how this module twice ended up rendering a
 * vocabulary the backend had already moved on from.
 */
const DISPOSITIONS: Record<string, string> = {
    offered: 'answered',
    countered: 'answered',
    replied: 'answered',
    escalated: 'needsReview',
    clarified: 'awaitingBuyer',
    nothing_to_do: 'noAction',
};

/**
 * `accepted` is the quote state SwagCommercial transitions to AFTER the order
 * exists: both callers of its `accept` action — QuoteOrderRoute and
 * OrderApproval's PendingOrderPlaceOrderRoute — write `quote.orderId` and only
 * then transition. Checked against the test shop as well as the code: all five
 * accepted quotes carry an order id, and all seventy in every other state carry
 * none.
 *
 * So this is not a rename of "accepted" for flavour. It is what the state
 * means, and it is the one outcome that says the negotiation earned revenue,
 * which is why it wins over whatever the last pass did.
 */
export const ORDER_PLACED_TERMINAL_STATE = 'accepted';

export const DISPOSITION_CLASSES = ['orderPlaced', 'needsReview', 'answered', 'awaitingBuyer', 'noAction', 'other'];

export function disposition(outcome: string | null, terminalState: string | null = null): string {
    if (terminalState === ORDER_PLACED_TERMINAL_STATE) {
        return 'orderPlaced';
    }

    return (outcome && DISPOSITIONS[outcome]) || 'other';
}

/**
 * Collapses servicing passes into one entry per quote.
 *
 * `decisions` must arrive newest-first: the first pass seen for a quote is its
 * current state, and Map preserves that insertion order so the result is still
 * newest-activity-first. One row per pass meant a three-round negotiation
 * appeared as three unrelated rows.
 */
export function foldToQuotes(decisions: any[]): any[] {
    const byQuote = new Map<string, any>();

    decisions.forEach((decision) => {
        const netBefore = Number(decision.totalNetBefore ?? 0);
        const seen = byQuote.get(decision.quoteId);

        if (seen) {
            seen.rounds += 1;
            // The quote's own value, not the latest pass's: a later pass can
            // start from an already-discounted total.
            seen.netBefore = Math.max(seen.netBefore, netBefore);
            // Any pass, not just the newest. TerminalOutcomeWriter stamps the
            // newest record at the time of the transition, and a pass that was
            // already in flight then inserts a newer one behind it — so
            // reading only `latest` loses the outcome on exactly the quote that
            // was cancelled or accepted mid-pass.
            seen.terminalState = seen.terminalState ?? decision.terminalState ?? null;
            seen.terminalAt = seen.terminalAt ?? decision.terminalAt ?? null;
            seen.disposition = disposition(seen.latest.outcome, seen.terminalState);

            return;
        }

        byQuote.set(decision.quoteId, {
            quoteId: decision.quoteId,
            quoteNumber: decision.quoteNumber,
            latest: decision,
            rounds: 1,
            netBefore,
            terminalState: decision.terminalState ?? null,
            terminalAt: decision.terminalAt ?? null,
            disposition: disposition(decision.outcome, decision.terminalState ?? null),
        });
    });

    return [...byQuote.values()];
}

export function outcomeVariant(outcome: string | null): string {
    return (outcome && OUTCOME_VARIANTS[outcome]) || 'neutral';
}

/**
 * The badge hue for a disposition, which is NOT the hue for the last pass's
 * outcome.
 *
 * They used to be read separately — the label off the disposition, the colour
 * off `latest.outcome` — and agreed only because the two maps happened to run
 * parallel. They stopped agreeing the moment `orderPlaced` could outrank the
 * pass: a quote that escalated and was then ordered read "Order placed" in
 * critical red. One value, one lookup.
 */
const DISPOSITION_VARIANTS: Record<string, string> = {
    orderPlaced: 'positive',
    answered: 'positive',
    needsReview: 'critical',
    awaitingBuyer: 'info',
    noAction: 'neutral',
    other: 'neutral',
};

export function dispositionVariant(key: string | null): string {
    return (key && DISPOSITION_VARIANTS[key]) || 'neutral';
}

export function bandVariant(band: string | null): string {
    return (band && BAND_VARIANTS[band]) || 'neutral';
}

/**
 * A snippet lookup that falls back to the raw stored value. Every one of these
 * maps a backend enum, so a value the snippets do not know is a real gap —
 * showing the technical value beats showing a snippet path.
 */
function labelled(vm: any, path: string, value: string | null): string {
    if (!value) {
        return '–';
    }

    const key = `merchant-quote-agent.${path}.${value}`;
    const label = vm.$tc(key);

    return label === key ? value : label;
}

export function outcomeLabel(vm: any, outcome: string | null): string {
    return labelled(vm, 'outcome', outcome);
}

export function escalationLabel(vm: any, reason: string | null): string {
    return labelled(vm, 'escalation', reason);
}

/**
 * Why a human was asked, in a sentence, with this pass's own numbers in it.
 *
 * The reason column is an enum and its label is four words — "Discount above
 * the cap" tells a merchant which bucket the pass fell into and nothing about
 * what to do. Everything needed to say more was already recorded: the cap in
 * force, what the buyer asked for, the quote's value, and `violations`, which
 * carries the escalation detail (DecisionRecorder::recordProposal writes it
 * there).
 *
 * Nothing new is stored for this. The sentence is composed from the row, so it
 * works on rows already in the table.
 */
export function escalationExplanation(vm: any, round: any): string | null {
    const reason = round.escalationReason;

    if (!reason) {
        return null;
    }

    const key = `merchant-quote-agent.escalationWhy.${reason}`;
    const asked = askedDiscountPercent(round.interpretedAsks);
    const sentence = vm.$t(key, {
        asked: asked === null ? vm.$tc('merchant-quote-agent.escalationWhy.anUnstatedAmount') : formatPercent(asked),
        cap: formatPercent(round.maxDiscountPercent),
        value: formatCurrency(round.totalNetBefore, round.currencyIso),
        currency: round.currencyIso || '–',
    });

    // A reason with no sentence of its own is a real gap — the enum grew and
    // the snippets did not — so fall back to the short label rather than
    // printing a snippet path at the merchant.
    return sentence === key ? escalationLabel(vm, reason) : sentence;
}

/**
 * What the buyer asked for as a discount, across both recorded ask shapes.
 * Null when they asked in some other way (a per-line target price, "best
 * price"), which is why the sentences that use it have an unstated-amount
 * wording to fall back on.
 */
function askedDiscountPercent(asks: any): number | null {
    if (!asks || typeof asks !== 'object') {
        return null;
    }

    const value = asks.targetDiscountPercent ?? asks.price?.additionalDiscountPercent;

    return typeof value === 'number' ? value : null;
}

export function triggerLabel(vm: any, reason: string | null): string {
    return labelled(vm, 'trigger', reason);
}

export function terminalLabel(vm: any, state: string | null): string {
    return labelled(vm, 'detail.terminal', state);
}

export function formatCurrency(value: number | null, currencyIso = 'EUR'): string {
    if (value === null || value === undefined) {
        return '–';
    }

    const filter = globalThis.Shopware?.Filter?.getByName?.('currency');

    return filter ? filter(value, currencyIso) : `${Number(value).toFixed(2)} ${currencyIso}`;
}

/** One decimal: the model returns discounts to four and they read as noise. */
export function formatPercent(value: number | null): string {
    if (value === null || value === undefined) {
        return '–';
    }

    return `${Number(value).toFixed(1)}%`;
}

/** Milliseconds are how it is stored; seconds are how a 22-second pass reads. */
export function formatDuration(ms: number | null): string {
    if (ms === null || ms === undefined) {
        return '–';
    }

    return ms < 1000 ? `${ms} ms` : `${(ms / 1000).toFixed(1)} s`;
}

export function formatDate(value: string | null): string {
    if (!value) {
        return '–';
    }

    const filter = globalThis.Shopware?.Filter?.getByName?.('date');

    return filter ? filter(value) : String(value);
}

/**
 * The same instant in a grid cell's worth of space.
 *
 * The default format ("4 September 2026 at 09:09") needs ~220px on one line,
 * and a data-grid column is sized by its widest unshrinkable content — so the
 * long form silently pushed the column past the grid's right edge. Still
 * locale-formatted; only the field widths are pinned.
 */
export function formatDateShort(value: string | null): string {
    if (!value) {
        return '–';
    }

    const filter = globalThis.Shopware?.Filter?.getByName?.('date');

    if (!filter) {
        return String(value);
    }

    return filter(value, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/**
 * What the buyer asked for, flattened to label/value rows.
 *
 * Reads CommentInterpretation as it is recorded — price / structural /
 * negotiation blocks. Records written before that shape existed are flat
 * (`targetDiscountPercent`, `lines[]`), and this is an audit table nobody
 * backfills, so both shapes are read. Reading only the flat one is why the ask
 * rendered empty on every current record.
 */
export function askItems(vm: any, asks: any): { label: string; value: string }[] {
    if (!asks || typeof asks !== 'object') {
        return [];
    }

    const items: { label: string; value: string }[] = [];
    const add = (key: string, value: string | null): void => {
        if (value !== null) {
            items.push({ label: vm.$tc(`merchant-quote-agent.ask.${key}`), value });
        }
    };

    const num = (v: any): boolean => typeof v === 'number';
    const percent = (v: any): string | null => (num(v) ? formatPercent(v) : null);
    const days = (v: any): string | null => (num(v) ? vm.$t('merchant-quote-agent.ask.days', { count: v }) : null);
    const flag = (v: any): string | null => (v === true ? vm.$tc('merchant-quote-agent.ask.requested') : null);
    const list = (v: any): string | null => (Array.isArray(v) && v.length > 0 ? v.join(' · ') : null);

    const price = asks.price ?? {};
    const structural = asks.structural ?? {};
    const delivery = asks.negotiation?.delivery ?? {};
    const payment = asks.negotiation?.payment ?? {};

    add('discount', percent(asks.targetDiscountPercent ?? price.additionalDiscountPercent));
    add('bestPrice', flag(price.bestPriceRequested));

    (asks.lines ?? structural.lineChanges ?? []).forEach((line: any, index: number) => {
        const suffix = ` #${index + 1}`;
        const target = line.targetUnitPriceNet ?? line.targetUnitPrice;

        if (num(target)) {
            items.push({ label: vm.$tc('merchant-quote-agent.ask.targetPrice') + suffix, value: formatCurrency(target) });
        }
        if (num(line.quantity)) {
            items.push({ label: vm.$tc('merchant-quote-agent.ask.quantity') + suffix, value: String(line.quantity) });
        }
        if (line.remove === true) {
            items.push({ label: vm.$tc('merchant-quote-agent.ask.removeLine') + suffix, value: vm.$tc('merchant-quote-agent.ask.requested') });
        }
    });

    if (Array.isArray(structural.addProducts) && structural.addProducts.length > 0) {
        add('addProducts', String(structural.addProducts.length));
    }

    add('validUntil', structural.validityUntilIsoDate ? formatDate(structural.validityUntilIsoDate) : null);
    add('leadTime', days(delivery.requestedLeadTimeDays));
    add('expedited', flag(delivery.expedited));
    add('freeShipping', flag(delivery.freeShipping));
    add('shippingCost', num(delivery.shippingCostNet) ? formatCurrency(delivery.shippingCostNet) : null);
    add('paymentTerm', payment.requestedTerm ?? null);
    add('netDays', days(payment.requestedNetDays));
    add('deposit', percent(payment.requestedDepositPercent));
    add('bundle', flag(asks.negotiation?.bundle?.requested));
    add('clarification', list(asks.clarificationQuestions));
    add('humanReview', list(asks.humanReviewRequests));

    return items;
}

/**
 * The quote's comment thread, attributed and oldest first.
 *
 * The buyer's own words are not in the decision table and deliberately stay
 * out of it — only their interpreted ask is recorded. They ARE on the quote,
 * where SwagCommercial keeps them, so the page reads them from there: no new
 * column, no copy of a customer's text in a second place, and the thread is
 * whatever the quote currently says rather than a snapshot that can drift.
 *
 * Authorship follows QuoteComment::isAuthored() exactly: a comment with any of
 * createdById / customerId / employeeId is a person's, and one with none of
 * them is the agent's. That is not elegant, it is what SwagCommercial writes —
 * issue #3 measured all three as null on an agent comment and AddCommentTest
 * pins it. If this and the backend ever disagree, the page would credit the
 * agent's own words to the customer, so the two must move together.
 */
export function conversation(comments: any[]): any[] {
    if (!Array.isArray(comments)) {
        return [];
    }

    return comments
        .map((comment) => ({
            id: comment.id,
            text: (comment.comment ?? '').trim(),
            fromAgent: !comment.createdById && !comment.customerId && !comment.employeeId,
            createdAt: comment.createdAt ?? null,
            lineItemId: comment.quoteLineItemId ?? null,
        }))
        .filter((entry) => entry.text !== '')
        .sort((a, b) => String(a.createdAt ?? '').localeCompare(String(b.createdAt ?? '')));
}

/** The same asks as one scannable line, for a grid cell. */
export function askSummary(vm: any, asks: any): string {
    const items = askItems(vm, asks);

    if (items.length === 0) {
        return '–';
    }

    const head = items.slice(0, 2).map((item) => `${item.label}: ${item.value}`).join(' · ');

    return items.length > 2 ? `${head} +${items.length - 2}` : head;
}
