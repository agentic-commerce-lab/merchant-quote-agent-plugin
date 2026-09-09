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
            //
            // `quote_comment:read` for the detail page's conversation card: the
            // customer's own words live on the quote and are read from there
            // rather than copied into the decision table, and an association
            // is checked against the associated entity's own privilege.
            privileges: ['merchant_quote_agent_decision:read', 'quote:read', 'quote_comment:read'],
            dependencies: [],
        },
        deleter: {
            privileges: ['merchant_quote_agent_decision:delete'],
            dependencies: ['merchant_quote_agent.viewer'],
        },
    },
};
