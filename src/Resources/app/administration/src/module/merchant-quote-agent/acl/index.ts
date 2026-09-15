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
            // `order:read` because the deal-cycle-time measure then reads the
            // core order repository for those quotes' order dates. Without it
            // the quote read still succeeds but every order date comes back
            // empty, which the tile also has to report as unavailable rather
            // than silently scoring every deal as having no cycle time.
            //
            // `quote_comment:read` for the detail page's conversation card: the
            // customer's own words live on the quote and are read from there
            // rather than copied into the decision table, and an association
            // is checked against the associated entity's own privilege.
            //
            // The strategy library. The config page's selector and the
            // decision detail's "which prompt was sent" both read these.
            privileges: [
                'merchant_quote_agent_decision:read',
                'quote:read',
                'order:read',
                'quote_comment:read',
                'merchant_quote_agent_strategy:read',
                'merchant_quote_agent_strategy_version:read',
            ],
            dependencies: [],
        },
        deleter: {
            privileges: ['merchant_quote_agent_decision:delete'],
            dependencies: ['merchant_quote_agent.viewer'],
        },
        // No delete on either entity, deliberately: removing a strategy is
        // archival (an update), because a decision must keep resolving the
        // version it used. Version rows are append-only, so create but never
        // update -- StrategyWriteGuard enforces both server-side.
        editor: {
            privileges: [
                'merchant_quote_agent_strategy:create',
                'merchant_quote_agent_strategy:update',
                'merchant_quote_agent_strategy_version:create',
            ],
            dependencies: ['merchant_quote_agent.viewer'],
        },
    },
};
