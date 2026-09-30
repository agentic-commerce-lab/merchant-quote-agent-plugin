/**
 * The Admin API as the eval reads and restores it (spec "Stage 1 — the UCP
 * buyer"). Integration credentials (client_credentials); the token is cached
 * until a minute before it expires. Raw entity search, not the anonymized
 * export: the export replaces the quote id with an HMAC pseudonym.
 */
export const CONFIG_DOMAIN = 'MerchantQuoteAgentPlugin.config';

export function adminClient({ shop, clientId, clientSecret, fetchImpl = fetch }) {
    let token = null;
    let expiresAt = 0;

    async function authorized() {
        if (token && Date.now() < expiresAt - 60_000) return token;
        const response = await fetchImpl(`${shop}/api/oauth/token`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ grant_type: 'client_credentials', client_id: clientId, client_secret: clientSecret }),
        });
        if (!response.ok) throw new Error(`the Admin API refused the integration credentials (HTTP ${response.status})`);
        const grant = await response.json();
        token = grant.access_token;
        expiresAt = Date.now() + grant.expires_in * 1000;
        return token;
    }

    async function call(method, path, body) {
        const response = await fetchImpl(`${shop}${path}`, {
            method,
            headers: { Authorization: `Bearer ${await authorized()}`, 'Content-Type': 'application/json', Accept: 'application/json' },
            body: body === undefined ? undefined : JSON.stringify(body),
        });
        const text = await response.text();
        if (!response.ok) throw new Error(`Admin API ${method} ${path}: HTTP ${response.status} ${text.slice(0, 300)}`);
        return text.trim() === '' ? null : JSON.parse(text);
    }

    const search = async (entity, criteria) => (await call('POST', `/api/search/${entity}`, criteria)).data;

    return {
        search,
        // `field` 'quoteNumber' lets promote.mjs take the number a merchant sees
        decisions: (value, field = 'quoteId') => search('merchant-quote-agent-decision', {
            filter: [{ type: 'equals', field, value }],
            sort: [{ field: 'createdAt', order: 'ASC' }],
            limit: 100,
        }),
        traces: (decisionIds, kinds = ['quote_before', 'quote_after']) => search('merchant-quote-agent-trace', {
            filter: [
                { type: 'equalsAny', field: 'decisionId', value: decisionIds },
                { type: 'equalsAny', field: 'kind', value: kinds },
            ],
            limit: 500,
        }),
        configAt: async (salesChannelId) => (await call('GET', `/api/_action/system-config?domain=${CONFIG_DOMAIN}${salesChannelId ? `&salesChannelId=${salesChannelId}` : ''}`)) ?? {},
        writeConfig: (salesChannelId, values) => call('POST', `/api/_action/system-config${salesChannelId ? `?salesChannelId=${salesChannelId}` : ''}`, values),
        product: async (id) => (await search('product', { ids: [id], limit: 1 }))[0] ?? null,
        writePurchasePrices: (id, purchasePrices) => call('PATCH', `/api/product/${id}`, { purchasePrices }),
        salesChannelFor: async (shopUrl) => {
            const domains = await search('sales-channel-domain', { filter: [{ type: 'equalsAny', field: 'url', value: [shopUrl, `${shopUrl}/`] }], limit: 1 });
            if (!domains[0]) throw new Error(`no sales channel domain matches ${shopUrl}`);
            return domains[0].salesChannelId;
        },
        pluginVersion: async () => (await search('plugin', { filter: [{ type: 'equals', field: 'name', value: 'MerchantQuoteAgentPlugin' }], limit: 1 }))[0]?.version ?? null,
    };
}
