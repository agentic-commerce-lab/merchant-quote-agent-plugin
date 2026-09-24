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
    handed_over: 'neutral',
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

const NOT_SENT = ['pending', 'rejected', 'superseded'];

export function answeredTheBuyer(outcome: string | null, reviewStatus: string | null = null): boolean {
    return outcome !== null && ANSWERED_OUTCOMES.includes(outcome) && !NOT_SENT.includes(reviewStatus ?? '');
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
 *
 * `escalated` is deliberately not a key here. Its disposition depends on
 * `resolvedAt` — a human's answer, not the outcome column — so `disposition()`
 * decides it above this map, before falling back to this table. Adding it
 * back here would be a second source of truth for the same outcome.
 */
const DISPOSITIONS: Record<string, string> = {
    offered: 'answered',
    countered: 'answered',
    replied: 'answered',
    clarified: 'awaitingBuyer',
    nothing_to_do: 'noAction',
    handed_over: 'noAction',
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

/**
 * The four terminal states that are not a sale. Mirrors the non-`accepted`
 * half of TerminalOutcomeSubscriber::TERMINAL_STATES — the two lists must move
 * together, and cannot be shared across the PHP/JS boundary.
 */
export const CLOSED_NO_DEAL = ['declined', 'expired', 'cancelled', 'withdrawn'];

export const DISPOSITION_CLASSES = ['orderPlaced', 'closedNoDeal', 'needsReview', 'awaitingReview', 'answered', 'awaitingBuyer', 'noAction', 'other'];

export function disposition(
    outcome: string | null,
    terminalState: string | null = null,
    resolvedAt: string | null = null,
    reviewStatus: string | null = null,
): string {
    if (terminalState === ORDER_PLACED_TERMINAL_STATE) {
        return 'orderPlaced';
    }

    // ANY terminal state outranks the pass outcome, not just `accepted`.
    // Before this, only `accepted` did, and the result was that an escalated
    // quote which ended declined, expired, cancelled or withdrawn read "Needs
    // review" forever — a queue that could never drain, on the grid's default
    // filter.
    if (terminalState !== null && CLOSED_NO_DEAL.includes(terminalState)) {
        return 'closedNoDeal';
    }

    if (reviewStatus === 'pending') {
        return 'awaitingReview';
    }

    if (reviewStatus === 'rejected') {
        return 'needsReview';
    }

    // An escalation a human has answered is waiting on the BUYER, which is
    // literally true once the merchant has replied. No class of its own is
    // needed, and the auto-execution rate still counts the quote against the
    // agent because that reads the `escalated` flag rather than this.
    if (outcome === 'escalated') {
        return resolvedAt ? 'awaitingBuyer' : 'needsReview';
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
            // Any pass, for the same reason plus one of its own: a quote that
            // escalated in round one and was answered in round two DID need a
            // human, so the auto-execution rate must count it.
            seen.escalated = seen.escalated || decision.outcome === 'escalated' || Boolean(decision.reviewStatus);
            // The newest escalated pass, since decisions arrive newest-first.
            // That is also the pass `disposition` asks about, because it only
            // consults `resolvedAt` when the LATEST pass escalated.
            if (decision.outcome === 'escalated' && seen.escalatedAt === null) {
                seen.escalatedAt = decision.createdAt ?? null;
                seen.resolvedAt = decision.resolvedAt ?? null;
            }
            // The newest pass that put an offer in front of the buyer. Price
            // retention needs the price the buyer actually saw, and the latest
            // pass may have escalated without offering anything.
            seen.latestAnswered = seen.latestAnswered ?? (answeredTheBuyer(decision.outcome, decision.reviewStatus ?? null) ? decision : null);
            seen.disposition = disposition(seen.latest.outcome, seen.terminalState, seen.resolvedAt, seen.latest.reviewStatus ?? null);

            return;
        }

        // Drafted passes needed a human, even when their eventual outcome was an offer.
        const escalated = decision.outcome === 'escalated' || Boolean(decision.reviewStatus);

        byQuote.set(decision.quoteId, {
            quoteId: decision.quoteId,
            quoteNumber: decision.quoteNumber,
            latest: decision,
            latestAnswered: answeredTheBuyer(decision.outcome, decision.reviewStatus ?? null) ? decision : null,
            rounds: 1,
            netBefore,
            terminalState: decision.terminalState ?? null,
            terminalAt: decision.terminalAt ?? null,
            escalated,
            escalatedAt: escalated ? decision.createdAt ?? null : null,
            resolvedAt: escalated ? decision.resolvedAt ?? null : null,
            disposition: disposition(
                decision.outcome,
                decision.terminalState ?? null,
                escalated ? decision.resolvedAt ?? null : null,
                decision.reviewStatus ?? null,
            ),
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
    awaitingReview: 'attention',
    awaitingBuyer: 'info',
    closedNoDeal: 'neutral',
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
 * The buyer's own words for why a human is needed, when this pass is the one
 * genuine case (HumanReviewEscalation) rather than one of the specific
 * escalation reasons split out by issue #169. Read off the same interpreted-
 * ask payload askItems() already reads for the decision drawer, so nothing
 * new is stored or fetched for it.
 *
 * Issue #169: previously rendered only inside askItems(), reached from the
 * per-pass drawer on the detail page — never on the list page's own
 * escalation badge, which is the surface a merchant actually scans. Empty for
 * every other escalation reason, since only HumanReviewEscalation populates
 * `humanReviewRequests`.
 */
export function humanReviewRequests(asks: any): string[] {
    const value = asks?.humanReviewRequests;

    return Array.isArray(value) ? value.filter((entry: unknown): entry is string => typeof entry === 'string' && entry !== '') : [];
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

/**
 * What the whole quote is off by, measured against the earliest pass's opening
 * total — the same snapshot `QuoteBaseline` stamps, and so the same number
 * `ReplyTemplate::reduction()` quotes at the buyer.
 *
 * Not `discountPercentGranted`: that is the reduction THIS pass made, and a
 * pass that holds the previous round's offer records 0 while the quote is
 * genuinely reduced. Live quote 1012 showed "0.0%" for rounds two and three
 * beside an already-reduced total and a reply saying 15%.
 */
export function quoteDiscountPercent(baselineNet: number | null, totalNetAfter: number | null): number | null {
    if (!Number.isFinite(baselineNet) || !Number.isFinite(totalNetAfter) || Number(baselineNet) <= 0) {
        return null;
    }

    return ((Number(baselineNet) - Number(totalNetAfter)) / Number(baselineNet)) * 100;
}

/** A sent draft's final price supersedes the agent's proposed pass total. */
export function answeredNetAfter(decision: unknown): number | null {
    if (typeof decision !== 'object' || decision === null) {
        return null;
    }

    const row = decision as Record<string, unknown>;
    const changes = row.sentChanges;
    const sent = row.reviewStatus === 'sent' && typeof changes === 'object' && changes !== null
        ? (changes as Record<string, unknown>).totalNet
        : null;
    const net = typeof sent === 'number' && Number.isFinite(sent) ? sent : row.totalNetAfter;

    return typeof net === 'number' && Number.isFinite(net) ? net : null;
}

/**
 * What the buyer saw on a sent draft, otherwise what the pass itself moved:
 * reduction and the two totals it is measured on. A pass holding the previous
 * offer says so in words rather than printing a zero as "no discount".
 *
 * Below 0.05pp is "unchanged" because the figure shows one decimal: a delta
 * that rounds away would render as "+0.0", which is exactly the ambiguity
 * this replaced. A negative delta is a pass that raised the price and stays
 * visible as one.
 */
export function roundChange(vm: any, round: any): string {
    const after = answeredNetAfter(round);
    const delta = round.reviewStatus === 'sent' && Number(round.totalNetBefore) > 0 && after !== null
        ? ((Number(round.totalNetBefore) - after) / Number(round.totalNetBefore)) * 100
        : round.discountPercentGranted;
    const change = typeof delta !== 'number' || !Number.isFinite(delta) || Math.abs(delta) < 0.05
        ? vm.$tc('merchant-quote-agent.detail.roundUnchanged')
        : vm.$t('merchant-quote-agent.detail.roundChange', { pp: `${delta > 0 ? '+' : ''}${delta.toFixed(1)}` });

    if (!Number.isFinite(round.totalNetBefore) || after === null) {
        return change;
    }

    return `${change} · ${formatCurrency(round.totalNetBefore, round.currencyIso)} → ${formatCurrency(after, round.currencyIso)}`;
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
    // A quote-level budget has no line to sit on, so this chip is the only
    // place it is visible before the offer that answers it.
    add('targetTotal', num(price.targetTotal) ? formatCurrency(price.targetTotal) : null);

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
    // Only ever set on records written before the volume ask became
    // `price.bestPriceRequested` (see NegotiationAsks). Kept so an old
    // decision still reads the way it was decided.
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
 * Authorship follows the backend's three-way split exactly: a comment with
 * customerId or employeeId is the buyer's, one with createdById alone is the
 * merchant's own message, and one with none of them is the agent's — issue #3
 * measured that last one and AddCommentTest pins it. That is not elegant, it
 * is what SwagCommercial writes. If this and the backend ever disagree, the
 * page credits the agent's own words to the customer, so the two must move
 * together — which is why #55 changed both.
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
            fromMerchant: !!comment.createdById && !comment.customerId && !comment.employeeId,
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

/**
 * What a pass actually changed on the quote, in the merchant's words.
 *
 * These are the write names OfferApplier records as it performs them — claim,
 * updateLineItems, updateQuote, recalculate — and they were only ever on the
 * page as raw method names inside the collapsed technical fold. Whether the
 * agent touched the quote at all is not a technical detail.
 *
 * An unmapped name falls back to itself: a write the applier learned and the
 * snippets did not is a real gap, and showing `updateSomething` beats
 * silently dropping the fact that something was written.
 */
export function writeLabels(vm: any, writes: any): string[] {
    if (!Array.isArray(writes)) {
        return [];
    }

    return writes.map((name) => labelled(vm, 'write', String(name)));
}

type PassNote = { key: string; text: string; variant: string; detail: string | null };

/**
 * The merchant-facing notes on a pass beyond its outcome badge: whether an
 * earlier attempt died, why a pass answered nothing, and whether the pass
 * still needs a person despite reporting success.
 *
 * That last one is the reason this exists. A pass can grant a discount, post
 * the reply, and fail to reach `replied` — see
 * DecisionRecorder::recordReplyTransitionFailed() — which sets `violations`
 * and NO escalation reason. Live quote #1021 read as a green "Offer sent"
 * with the buyer holding a discount they could not accept, and the only trace
 * sat in the technical fold nobody opens.
 *
 * Nothing is emitted for a pass that escalated: escalationExplanation()
 * already gives it a sentence with its own numbers in it, and repeating the
 * flags underneath would say the same thing in weaker words.
 */
export function passNotes(vm: any, round: any): PassNote[] {
    const note = (
        key: string,
        variant: string,
        values: Record<string, unknown> | null = null,
        detail: string | null = null,
    ): PassNote => ({
        key,
        variant,
        detail,
        text: values
            ? vm.$t(`merchant-quote-agent.note.${key}`, values)
            : vm.$tc(`merchant-quote-agent.note.${key}`),
    });

    const notes: PassNote[] = [];

    // ServiceQuoteHandler's crash budget, not a delivery count: a positive
    // attempt means an earlier pass on this quote killed the worker process.
    if (typeof round.attempt === 'number' && round.attempt > 0) {
        notes.push(note('attemptRetried', 'attention', { count: round.attempt }));
    }

    if (round.outcome === 'nothing_to_do') {
        notes.push(note('nothingToDo', 'neutral'));
    }

    if (round.outcome === 'handed_over') {
        notes.push(note('handedOver', 'neutral'));
    }

    if (round.escalationReason) {
        return notes;
    }

    if (round.authorized === false) {
        notes.push(note('notAuthorized', 'critical'));
    }

    if (round.verified === false) {
        notes.push(note('notVerified', 'critical'));
    }

    if (Array.isArray(round.violations) && round.violations.length > 0) {
        notes.push(note('needsAttention', 'critical', null, round.violations.join('; ')));
    }

    return notes;
}

/**
 * Why the negotiation ended the way it did, or null when the state has no
 * sentence of its own — a terminal state is an enum, and printing the raw
 * value where a sentence belongs reads as a bug rather than as an
 * explanation. The short label above it already names the state.
 */
export function terminalExplanation(vm: any, state: string | null): string | null {
    if (!state) {
        return null;
    }

    const key = `merchant-quote-agent.detail.terminalWhy.${state}`;
    const sentence = vm.$tc(key);

    return sentence === key ? null : sentence;
}

/**
 * The one sequence the detail page renders: what the buyer wrote, what each
 * pass did about it, and how the quote ended, in the order it happened.
 *
 * Two cards said the same sentence twice — the agent's quote comment in one,
 * the pass's `replyToBuyer` in the other — and left the merchant to line up
 * timestamps by eye to see which comment caused which pass. So the agent's
 * comments are dropped here on purpose: that text renders inside the pass
 * that wrote it, where the decision behind it also lives.
 *
 * Sorted on the recorded instant. Ties keep buyer comments ahead of passes,
 * because Array.sort is stable and the comments are inserted first — a pass
 * that answers a comment within the same second is still the answer to it.
 * An entry with no timestamp sorts last rather than first: `terminalState`
 * and `terminalAt` are stamped together, but a row carrying only the state
 * still describes the end of the negotiation, not its beginning.
 */
/**
 * The buyer's asks as the PASSES recorded them, for a page that cannot read
 * the quote's own comments: SwagCommercial absent or unlicensed, a role
 * without `quote_comment:read`, or a quote that no longer exists.
 *
 * A fallback, never an addition — the quote is the better source when it can
 * be read, and merging both would print every ask twice. Silent when nothing
 * was recorded, which is also what a pass answering a structured per-line ask
 * leaves behind: no comment was read, so there is nothing to show.
 */
function recordedAsks(runs: any[]): any[] {
    return runs
        .filter((run) => typeof run.raw?.buyerAsk === 'string' && run.raw.buyerAsk.trim() !== '')
        .map((run) => ({
            id: `ask-${run.id}`,
            text: run.raw.buyerAsk.trim(),
            fromAgent: false,
            fromMerchant: false,
            createdAt: run.raw.createdAt ?? null,
            lineItemId: null,
        }));
}

export function mergeStream(
    messages: any[],
    runs: any[],
    terminal: { state: string | null; at: string | null } | null,
): any[] {
    const heard = messages.filter((message) => !message.fromAgent);

    const entries: any[] = [
        ...(heard.length > 0 ? heard : recordedAsks(runs))
            .map((message) => ({ kind: 'message', key: message.id, at: message.createdAt, variant: 'neutral', message })),
        ...runs.map((run) => ({ kind: 'pass', key: run.id, at: run.raw.createdAt, variant: run.outcomeVariant, run })),
    ];

    if (terminal?.state) {
        entries.push({
            kind: 'outcome',
            key: 'outcome',
            at: terminal.at,
            variant: terminal.state === ORDER_PLACED_TERMINAL_STATE ? 'positive' : 'neutral',
            state: terminal.state,
        });
    }

    return entries.sort((a, b) => String(a.at ?? '￿').localeCompare(String(b.at ?? '￿')));
}
