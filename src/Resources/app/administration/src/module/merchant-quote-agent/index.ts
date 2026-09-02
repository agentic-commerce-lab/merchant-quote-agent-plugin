import { privileges } from './acl';
import './page/merchant-quote-agent-list';
import './page/merchant-quote-agent-detail';
import './page/merchant-quote-agent-access';

import deDE from './snippet/de.json';
import enGB from './snippet/en.json';

Shopware.Service('privileges').addPrivilegeMappingEntry(privileges);

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
        access: {
            component: 'merchant-quote-agent-access',
            path: 'access',
            meta: {
                parentPath: 'merchant.quote.agent.index',
                // The Agentic Commerce plugin's privileges, deliberately: this
                // page reads and writes that plugin's config through its API, so
                // its ACL is the one that actually gates the data.
                privilege: 'ucp.viewer',
            },
        },
    },

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
        {
            id: 'merchant-quote-agent-access',
            label: 'merchant-quote-agent.access.mainMenuItem',
            path: 'merchant.quote.agent.access',
            parent: 'sw-order',
            position: 31,
            privilege: 'ucp.viewer',
        },
    ],
});
