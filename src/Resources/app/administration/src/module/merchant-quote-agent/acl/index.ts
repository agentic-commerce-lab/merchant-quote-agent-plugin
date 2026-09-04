export const privileges = {
    category: 'permissions',
    parent: null,
    key: 'merchant_quote_agent',
    roles: {
        viewer: {
            // `quote:read` because the overview's intake figures — quotes
            // received, expired unanswered — aggregate the quote entity. Without
            // it the aggregation 403s and the whole figures card disappears with
            // no explanation.
            privileges: ['merchant_quote_agent_decision:read', 'quote:read'],
            dependencies: [],
        },
        deleter: {
            privileges: ['merchant_quote_agent_decision:delete'],
            dependencies: ['merchant_quote_agent.viewer'],
        },
    },
};
