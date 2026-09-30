/**
 * Which shop config the eval changes, and how it puts it back (spec "Phase
 * B"). Every key is read with get(key, salesChannelId): a channel value wins
 * over the global one. So a key is written where its effective value lives,
 * and restored there -- to `null` (delete) when it was unset, never to a
 * default the merchant never chose.
 */
import { CONFIG_DOMAIN } from './admin.mjs';
import { POLICY_KEYS } from './scenarios.mjs';

const full = (key) => `${CONFIG_DOMAIN}.${key}`;

/** The shop policy every scenario expectation assumes (preflight asserts it); a scenario's `policy` overrides from here. */
export const EXPECTED_DEFAULTS = { maxDiscountPercent: 15, counterOfferMaxPercent: 25 };

export function effectivePolicy(globalValues, channelValues) {
    const value = (key) => channelValues[full(key)] ?? globalValues[full(key)] ?? null;
    return {
        maxDiscountPercent: value('maxDiscountPercent'),
        counterOfferMaxPercent: value('counterOfferMaxPercent'),
        minMarginPercent: value('minMarginPercent'),
        roundingMode: value('roundingMode') ?? 'off',
        roundingStep: value('roundingStep'),
    };
}

export function planSettings({ globalValues, channelValues, overrides }) {
    const writes = [];
    const restore = [];
    for (const key of POLICY_KEYS.filter((k) => k in overrides)) {
        const name = full(key);
        const scope = name in channelValues ? 'channel' : 'global';
        const previous = (scope === 'channel' ? channelValues : globalValues)[name] ?? null;
        writes.push({ scope, key: name, value: overrides[key] });
        restore.push({ scope, key: name, value: previous });
    }
    return { writes, restore };
}

/** Writes `entries` ({scope, key, value}) grouped by scope; used for both apply and restore. */
export async function applyWrites(admin, salesChannelId, entries) {
    for (const scope of ['global', 'channel']) {
        const values = Object.fromEntries(entries.filter((e) => e.scope === scope).map((e) => [e.key, e.value]));
        if (Object.keys(values).length > 0) await admin.writeConfig(scope === 'channel' ? salesChannelId : null, values);
    }
}

/**
 * The product's own net purchase price, for H3, when a margin floor is in
 * force; {} when none applies. Same currency as the price the eval renders,
 * as Bridge\PurchasePriceReader reads it for the quote's currency.
 */
export function purchasePricesFor(product, productId, policy) {
    if (policy.minMarginPercent === null || policy.minMarginPercent === undefined) return {};
    const currencyId = product.price?.[0]?.currencyId;
    const entry = (product.purchasePrices ?? []).find((price) => price.currencyId === currencyId);
    return entry && entry.net > 0 ? { [productId]: entry.net } : {};
}

/** A price list as the DAL accepts it on write: an Admin API read adds `extensions` and `apiAlias`. */
export function writablePrices(prices) {
    if (!Array.isArray(prices)) return null;
    const clean = ({ currencyId, net, gross, linked, listPrice, percentage, regulationPrice }) => ({
        currencyId, net, gross, linked,
        listPrice: listPrice ? clean(listPrice) : null,
        percentage: percentage ?? null,
        regulationPrice: regulationPrice ? clean(regulationPrice) : null,
    });
    return prices.map(clean);
}
