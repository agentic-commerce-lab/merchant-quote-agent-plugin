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

export function outcomeVariant(outcome: string | null): string {
    return (outcome && OUTCOME_VARIANTS[outcome]) || 'neutral';
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

/** The same asks as one scannable line, for a grid cell. */
export function askSummary(vm: any, asks: any): string {
    const items = askItems(vm, asks);

    if (items.length === 0) {
        return '–';
    }

    const head = items.slice(0, 2).map((item) => `${item.label}: ${item.value}`).join(' · ');

    return items.length > 2 ? `${head} +${items.length - 2}` : head;
}
