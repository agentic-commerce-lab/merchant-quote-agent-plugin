/** Account history audit values, kept separate from the decision vocabulary. */
type Translator = {
    $tc: (key: string) => string;
    $t: (key: string, values: Record<string, string | number>) => string;
};

const PREFIX = 'merchant-quote-agent.history.';
const COUNT_FIELDS = ['quotesSeen', 'quotesConverted', 'quotesLost', 'offersMade', 'offersAccepted'];
const REQUEST_KINDS = ['quote_history', 'orders', 'product_purchases'];

function record(value: unknown): Record<string, unknown> | null {
    return value !== null && typeof value === 'object' && !Array.isArray(value)
        ? value as Record<string, unknown>
        : null;
}

function text(value: unknown): string | null {
    return typeof value === 'string' && value.trim() !== '' ? value : null;
}

function number(value: unknown, decimals: number | null, unknown: string): string {
    if (typeof value !== 'number' || !Number.isFinite(value) || value < 0) return unknown;
    if (decimals === null) return Number.isSafeInteger(value) ? String(value) : unknown;

    return value.toFixed(decimals);
}

/** Unknown or incomplete audit data is visible; it never implies no history. */
export function historySummary(vm: Translator, value: unknown): string {
    if (value === null || value === undefined) return '–';

    const summary = record(value);
    const label = (key: string): string => vm.$tc(PREFIX + key);
    const unknown = label('unknown');
    if (!summary) return unknown;
    if (summary.available === false) return `${label('unavailable')}: ${text(summary.reason) ?? unknown}`;
    if (summary.available !== true) return unknown;

    const lines = COUNT_FIELDS.map((key) => `${label(key)}: ${number(summary[key], null, unknown)}`);
    const discount = number(summary.lastGrantedDiscountPercent, 2, unknown);
    lines.push(`${label('lastGrantedDiscount')}: ${discount === unknown ? unknown : discount + '%'}`);
    lines.push(`${label('orderCount')}: ${number(summary.orderCount, null, unknown)}`);
    lines.push(`${label('lifetimeNet')}: ${number(summary.lifetimeNet, 2, unknown)}`);
    const date = text(summary.lastOrderAt);
    const lastOrder = date !== null && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/.test(date) && Number.isFinite(Date.parse(date))
        ? date
        : (summary.lastOrderAt === null && summary.orderCount === 0 ? label('none') : unknown);
    lines.push(`${label('lastOrderAt')}: ${lastOrder}`);
    if (COUNT_FIELDS.every((key) => summary[key] === 0) && summary.orderCount === 0 && summary.lifetimeNet === 0) {
        lines.unshift(label('none'));
    }

    return lines.join('\n');
}

/** Keep order, numbering and complete results, including refusals and unfamiliar slots. */
export function historyReads(vm: Translator, value: unknown): string {
    if (value === null || value === undefined) return '–';

    const history = record(value);
    const unknown = vm.$tc(PREFIX + 'unknown');
    if (!history) return unknown;
    if (history.rounds === undefined || history.rounds === null) return '–';
    if (!Array.isArray(history.rounds)) return unknown;
    if (history.rounds.length === 0) return vm.$tc(PREFIX + 'none');

    return history.rounds.map((value, index) => {
        const round = record(value);
        const kind = text(round?.kind);
        const kindLabel = kind !== null && REQUEST_KINDS.includes(kind) ? vm.$tc(PREFIX + kind) : unknown;
        const productId = text(round?.productId);
        const product = productId === null ? '' : ` · ${vm.$tc(PREFIX + 'productId')}: ${productId}`;
        const heading = vm.$t(PREFIX + 'round', { count: index + 1 });

        return `${heading}: ${kindLabel}${product}\n${text(round?.result) ?? unknown}`;
    }).join('\n\n');
}
