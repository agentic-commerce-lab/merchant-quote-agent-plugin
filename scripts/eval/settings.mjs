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
