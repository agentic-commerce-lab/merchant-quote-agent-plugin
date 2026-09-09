export const privileges = {
    category: 'permissions',
    parent: null,
    key: 'merchant_quote_agent',
    roles: {
        viewer: {
            // `quote:read` because the dashboard's discount-retention and
            // deal-cycle-time measures read the quote entity for accepted
            // quotes. Without it that read 403s and both tiles report
            // themselves unavailable rather than a wrong number.
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
