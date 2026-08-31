import { privileges } from './acl';
import './page/merchant-quote-agent-list';
import './page/merchant-quote-agent-detail';

import deDE from './snippet/de.json';
import enGB from './snippet/en.json';

Shopware.Service('privileges').addPrivilegeMappingEntry(privileges);

Shopware.Module.register('merchant-quote-agent', {
    type: 'plugin',
    name: 'merchant-quote-agent',
    title: 'merchant-quote-agent.general.mainMenuItemGeneral',
    description: 'merchant-quote-agent.general.description',
    color: '#57D9A3',
    icon: 'regular-robot',
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
    },

    navigation: [
        {
            id: 'merchant-quote-agent',
            label: 'merchant-quote-agent.general.mainMenuItemGeneral',
            color: '#57D9A3',
            path: 'merchant.quote.agent.index',
            icon: 'regular-robot',
            parent: 'sw-order',
            position: 30,
            privilege: 'merchant_quote_agent.viewer',
        },
    ],
});
