import { privileges, reviewPrivileges } from './acl';
import './merchant-quote-agent.scss';
import './page/merchant-quote-agent-list';
import './page/merchant-quote-agent-detail';
import './page/merchant-quote-agent-access';
import './page/merchant-quote-agent-strategies';
import './page/merchant-quote-agent-improvements';
import './component/merchant-quote-agent-strategy-select';
import './component/merchant-quote-agent-draft-review';
import './component/merchant-quote-agent-feedback-modal';

import deDE from './snippet/de.json';
import enGB from './snippet/en.json';

Shopware.Service('privileges').addPrivilegeMappingEntry(privileges);
Shopware.Service('privileges').addPrivilegeMappingEntry(reviewPrivileges);

/**
 * Whether this shop has the Agentic Commerce plugin.
 *
 * The Agent Access page edits that plugin's UCP config through that plugin's own
 * admin API (`_admin/ucp/*`), so without it the page has nothing to read and
 * nothing to write — and the route is switched off rather than left to fail on
 * its first request. Everything else in this module works either way: quotes a
 * buyer creates by hand are serviced, decided and logged with no UCP surface
 * involved. Mirrors the PHP-side gate in `MerchantQuoteAgentPlugin\Ucp\UcpAvailability`.
 *
 * `config.bundles` is core's own way of asking (`sw-search-bar/index.js` tests
 * this very bundle the same way) and it is populated before any plugin entry
 * file runs — it is the list the administration reads to find them.
 */
const hasAgenticCommerce = Boolean(Shopware.Context.app.config.bundles?.SwagAgenticCommerce);

Shopware.Module.register('merchant-quote-agent', {
    type: 'plugin',
    name: 'merchant-quote-agent',
    title: 'merchant-quote-agent.general.mainMenuItemGeneral',
    description: 'merchant-quote-agent.general.description',
    color: '#57D9A3',
    icon: 'regular-chart-bar',
    entity: 'merchant_quote_agent_decision',

    snippets: {
        'de-DE': deDE,
        'en-GB': enGB,
    },

    routes: {
        index: {
            component: 'merchant-quote-agent-list',
            path: 'index',
            meta: {
                privilege: 'merchant_quote_agent.viewer',
            },
        },
        detail: {
            component: 'merchant-quote-agent-detail',
            path: 'detail/:id',
            meta: {
                parentPath: 'merchant.quote.agent.index',
                privilege: 'merchant_quote_agent.viewer',
            },
        },
        // Not gated on hasAgenticCommerce: the strategy library reads and
        // writes only this plugin's own entities, so it works on any shop,
        // unlike Agent access below which drives Agentic Commerce's API.
        strategies: {
            component: 'merchant-quote-agent-strategies',
            path: 'strategies',
            meta: {
                parentPath: 'sw.settings.index',
                privilege: 'merchant_quote_agent.viewer',
            },
        },
        // Unconditional, like strategies above: it reads this plugin's own
        // improvement_run and strategy_version entities only.
        improvements: {
            component: 'merchant-quote-agent-improvements',
            path: 'improvements',
            meta: {
                parentPath: 'sw.settings.index',
                privilege: 'merchant_quote_agent.viewer',
            },
        },
        ...(hasAgenticCommerce
            ? {
                access: {
                    component: 'merchant-quote-agent-access',
                    path: 'access',
                    meta: {
                        // Back out to Settings, which is where the page is reached from.
                        parentPath: 'sw.settings.index',
                        // The Agentic Commerce plugin's privileges, deliberately: this
                        // page reads and writes that plugin's config through its API, so
                        // its ACL is the one that actually gates the data.
                        privilege: 'ucp.viewer',
                    },
                },
            }
            : {}),
    },

    /**
     * Both are configuration, not a daily order task, so they belong in
     * Settings rather than under Orders next to the decision log. `plugins` is
     * the group an admin looks in for an extension's own configuration.
     *
     * The strategy library entry is unconditional: it reads and writes only
     * this plugin's own entities, so it belongs on every shop. Agent access
     * stays behind the bundle check below it.
     *
     * Both carry an explicit `name`. A settings item without one inherits the
     * MODULE's name, and the settings store drops an item whose name is already
     * taken (`settingsItems.addItem`), so two unnamed items from one module
     * collapse into whichever registers first — silently, and the page it points
     * at stays routable, which is what made this look like a missing page rather
     * than a missing link. `name` is also the list's render key.
     *
     * sw-settings-index filters items by `privilege`, so Agent access stays
     * hidden from anyone who cannot read the config it edits. That filter is
     * not enough on its own to hide it on a shop without the Agentic Commerce
     * plugin, though: an administrator's role grants every privilege,
     * `ucp.viewer` included, whether or not anything defines it. Hence the
     * bundle check.
     */
    settingsItem: [
        {
            name: 'merchant-quote-agent-strategies',
            group: 'plugins',
            to: 'merchant.quote.agent.strategies',
            icon: 'regular-comments',
            label: 'merchant-quote-agent.strategy.mainMenuItem',
            privilege: 'merchant_quote_agent.viewer',
        },
        {
            name: 'merchant-quote-agent-improvements',
            group: 'plugins',
            to: 'merchant.quote.agent.improvements',
            icon: 'regular-lightbulb',
            label: 'merchant-quote-agent.improvement.mainMenuItem',
            privilege: 'merchant_quote_agent.viewer',
        },
        ...(hasAgenticCommerce
            ? [
                {
                    name: 'merchant-quote-agent-access',
                    group: 'plugins',
                    to: 'merchant.quote.agent.access',
                    icon: 'regular-shield',
                    label: 'merchant-quote-agent.access.mainMenuItem',
                    privilege: 'ucp.viewer',
                },
            ]
            : []),
    ],

    navigation: [
        {
            id: 'merchant-quote-agent',
            label: 'merchant-quote-agent.general.mainMenuItemGeneral',
            color: '#57D9A3',
            path: 'merchant.quote.agent.index',
            icon: 'regular-chart-bar',
            parent: 'sw-order',
            position: 30,
            privilege: 'merchant_quote_agent.viewer',
        },
    ],
});
