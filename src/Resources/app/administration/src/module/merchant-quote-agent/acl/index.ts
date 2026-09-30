export const privileges = {
    category: 'permissions',
    parent: null,
    key: 'merchant_quote_agent',
    roles: {
        viewer: {
            // `merchant_quote_agent_trace:read` because a decision's trace is
            // part of the decision record: the export reads it under the
            // viewer's own context.
            //
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
            //
            // The assignment ladder's rows, which the strategies page reads to
            // show which customers and rules point at each strategy.
            //
            // `rule:read` because the rules grid reads rule names and
            // priorities to display beside each assignment row. Without it
            // that read 403s and the grid cannot show which rule an
            // assignment names.
            //
            // `sales_channel:read` because all three assignment grids'
            // channel-scope select (pins, rules, split arms) reads sales
            // channel names so a merchant can choose one to scope a row to.
            // Without it that read 403s on every grid, not just one.
            //
            // `customer:read` because the pins grid's customer select needs
            // it to show which customer a pin targets. Called out on its own
            // because it is a real privilege expansion, not a read already
            // reachable some other way: it lets anyone holding only this
            // plugin's viewer role read customer records, which carry
            // personal data. `viewer` already reaches customer data through
            // associations on `quote:read` and `order:read`, so this widens
            // an existing reach rather than opening a new one -- but it is
            // still a widening, worth a reviewer seeing stated rather than
            // discovering on their own.
            //
            // The nightly run's own output: what it proposed and how it
            // scored, so the accept/reject screen has something to show
            // before the editor role's privilege lets a human act on it.
            privileges: [
                'merchant_quote_agent_decision:read',
                'merchant_quote_agent_trace:read',
                'quote:read',
                'order:read',
                'quote_comment:read',
                'merchant_quote_agent_strategy:read',
                'merchant_quote_agent_strategy_version:read',
                'merchant_quote_agent_strategy_assignment:read',
                'rule:read',
                'sales_channel:read',
                'customer:read',
                'merchant_quote_agent_improvement_run:read',
            ],
            dependencies: [],
        },
        deleter: {
            privileges: ['merchant_quote_agent_decision:delete'],
            dependencies: ['merchant_quote_agent.viewer'],
        },
        // No delete on the strategy or version entities, deliberately:
        // removing a strategy is archival (an update), because a decision
        // must keep resolving the version it used.
        //
        // Version rows are append-only EXCEPT the one transition out of
        // `proposed` -- a human accepting or rejecting a nightly proposal --
        // which is what `update` covers here. StrategyWriteGuard is what
        // actually bounds that transition server-side; this privilege only
        // gets the request past the ACL gate a bare admin-API token still has
        // to clear.
        //
        // Assignments are different again, and genuinely get `delete`: no
        // decision record references an assignment row the way it references a
        // strategy version, so removing a pin or a split arm destroys no
        // history. This is not an oversight left over from the lines above --
        // an assignment is disposable in a way a strategy is not.
        editor: {
            privileges: [
                'merchant_quote_agent_strategy:create',
                'merchant_quote_agent_strategy:update',
                'merchant_quote_agent_strategy_version:create',
                'merchant_quote_agent_strategy_assignment:create',
                'merchant_quote_agent_strategy_assignment:update',
                'merchant_quote_agent_strategy_assignment:delete',
                'merchant_quote_agent_strategy_version:update',
            ],
            dependencies: ['merchant_quote_agent.viewer'],
        },
    },
};

/** Review routes require decision:update; the decision DAL entity remains write-protected. */
export const reviewPrivileges = {
    category: 'additional_permissions',
    parent: null,
    key: 'merchant_quote_agent_drafts',
    roles: {
        review: {
            // Reaches further than the two privileges it lists. Send reprices
            // the live quote, posts a comment and moves the quote to replied
            // (Review\MerchantSendContext grants that one write
            // `quote:update`, `quote_comment:create` and
            // `quote_history:create`), so a user holding only this role and
            // the viewer role can change a quote that SwagCommercial's own
            // permissions would not let them touch. Called out for the same
            // reason as the viewer's `customer:read`: a widening a reviewer
            // should see stated, not discover. It reaches only a pending
            // draft's quote, and only by sending what the agent prepared or
            // the reviewer edited.
            privileges: ['merchant_quote_agent_decision:read', 'merchant_quote_agent_decision:update'],
            dependencies: ['merchant_quote_agent.viewer'],
        },
    },
};
