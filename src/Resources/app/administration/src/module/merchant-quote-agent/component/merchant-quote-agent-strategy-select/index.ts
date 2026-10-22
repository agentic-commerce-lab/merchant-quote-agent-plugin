import template from './merchant-quote-agent-strategy-select.html.twig';
import {
    builtInSnippetKey,
    isBuiltIn,
    selectableStrategies,
    sortStrategies,
    versionedIds,
    VERSIONED_AGGREGATION,
} from '../../strategy.ts';

const { Criteria } = Shopware.Data;

/**
 * The Negotiation strategy card in the plugin configuration.
 *
 * Assignment only: which strategy this sales channel negotiates with. Creating
 * and editing strategies lives on Settings -> Negotiation strategies (Task 12),
 * because this card is saved by the configuration page's own Save button and
 * entity CRUD is not -- two save models in one card is the confusion worth
 * avoiding.
 *
 * `value` is the strategy's LINEAGE id, never a version id, so editing a
 * strategy takes effect on every channel using it. The decision row records
 * whichever version was actually sent.
 *
 * The plugin configuration page is reachable with `system_config:read` alone,
 * so a role without this module's `merchant_quote_agent.viewer` privilege would
 * otherwise see an empty select and conclude the feature is broken. Hence the
 * explicit hint below rather than a silent empty list.
 *
 * `compact` is the assignment grids' mode (Settings -> Negotiation strategies
 * -> Assignments): just the select, no label/help/badge/description/prompt/
 * manage-link, and no per-row prompt fetch -- see loadPromptSafely(). The
 * `strategies` and `versionedStrategyIds` props let a page that already loaded
 * both (the same page, for its library tab) pass them in instead of every grid
 * row calling load() for itself.
 */
Shopware.Component.register('merchant-quote-agent-strategy-select', {
    template,

    inject: ['repositoryFactory', 'acl'],

    props: {
        value: {
            type: String,
            required: false,
            default: null,
        },
        compact: {
            type: Boolean,
            required: false,
            default: false,
        },
        // The assignment grids pass `!canEdit`, and also true while that
        // row's own save is in flight -- see isRowSaving() on the strategies
        // page. Without a prop for it, a viewer could change the value here
        // (the other columns already forward :disabled) and only find out
        // it was refused after the 403 came back.
        disabled: {
            type: Boolean,
            required: false,
            default: false,
        },
        strategies: {
            type: Array,
            required: false,
            default: null,
        },
        // Pass together with `strategies`: the ids of every strategy that has
        // a version. Without it a passed-in list is filtered against an empty
        // set and the select offers nothing -- deliberately, since offering a
        // strategy it cannot vouch for would let a merchant assign one that
        // escalates every matching quote.
        versionedStrategyIds: {
            type: Set,
            required: false,
            default: null,
        },
    },

    emits: ['update:value'],

    data() {
        return {
            ownStrategies: [],
            ownVersionedIds: new Set(),
            prompt: '',
            isLoading: false,
            error: null,
        };
    },

    computed: {
        canRead() {
            return this.acl.can('merchant_quote_agent.viewer');
        },

        repository() {
            return this.repositoryFactory.create('merchant_quote_agent_strategy');
        },

        versionRepository() {
            return this.repositoryFactory.create('merchant_quote_agent_strategy_version');
        },

        /** The `strategies` prop when the caller passed one, otherwise this component's own load(). */
        resolvedStrategies() {
            return this.strategies ?? this.ownStrategies;
        },

        /** The `versionedStrategyIds` prop when passed, otherwise what load() found. */
        resolvedVersionedIds() {
            return this.versionedStrategyIds ?? this.ownVersionedIds;
        },

        options() {
            return selectableStrategies(this.resolvedStrategies, this.resolvedVersionedIds).map((strategy) => ({
                value: strategy.id,
                label: this.displayName(strategy),
            }));
        },

        selected() {
            return this.resolvedStrategies.find((strategy) => strategy.id === this.value) ?? null;
        },

        selectedIsBuiltIn() {
            return isBuiltIn(this.value);
        },

        /**
         * A saved row can point at a strategy `options` no longer offers --
         * archived, or never given a prompt -- since selectableStrategies
         * filters both out. In full mode that is left visible on purpose (see
         * `selected` above); in compact mode the select just renders blank
         * with no explanation, while every quote that row matches is
         * silently escalated to a human. This is what the compact-mode
         * notice in the template is keyed on.
         */
        valueIsSelectable() {
            // An empty resolvedStrategies means the load hasn't finished (or
            // failed) yet, not that every strategy is genuinely gone: three
            // built-in strategies always exist, so a list that actually
            // finished loading is never empty. Treating "empty" as "nothing
            // matches" here would flash the critical "unusable" notice below
            // on every saved row for the entire duration of the page's
            // strategy fetch -- do not remove this guard.
            if (this.resolvedStrategies.length === 0) {
                return true;
            }

            return this.value === null || this.value === '' || this.options.some((option) => option.value === this.value);
        },

        /**
         * Named from `resolvedStrategies` when it is still there -- a
         * versionless strategy is (selectableStrategies only filters it out
         * of `options`, not out of the list itself). An archived one is
         * excluded from the list the strategies page passes in, the same way
         * its own library tab excludes it (see that page's `load()`), so
         * this falls back to a generic label, the same pattern as
         * saveAssignment()'s unknownCustomer/unknownRule.
         */
        unresolvedStrategyName() {
            const strategy = this.resolvedStrategies.find((candidate) => candidate.id === this.value) ?? null;

            return strategy === null ? this.$tc('merchant-quote-agent.strategy.unresolvedName') : this.displayName(strategy);
        },

        description() {
            if (this.selected === null) {
                return '';
            }

            const key = builtInSnippetKey(this.selected.id);

            return key === null
                ? (this.selected.description ?? '')
                : this.$tc(`merchant-quote-agent.strategy.builtIn.${key}.description`);
        },
    },

    watch: {
        value() {
            // Compact rows never show the prompt -- see the component
            // docblock -- so there is nothing here worth a fetch.
            if (this.compact) {
                return;
            }

            this.loadPromptSafely();
        },
    },

    created() {
        // A passed-in list means the parent already loaded it; calling
        // load() here too would be the exact per-row duplicate fetch compact
        // mode exists to avoid.
        if (this.strategies === null) {
            this.load();
        }
    },

    methods: {
        displayName(strategy) {
            const key = builtInSnippetKey(strategy.id);

            return key === null ? strategy.name : this.$tc(`merchant-quote-agent.strategy.builtIn.${key}.name`);
        },

        onUpdateValue(value) {
            this.$emit('update:value', value);
        },

        async load() {
            if (!this.canRead) {
                return;
            }

            this.isLoading = true;

            try {
                const criteria = new Criteria(1, 100);
                // Archived and versionless strategies are not offered. One
                // already selected still resolves server-side -- and refuses
                // loudly, which is the intended behaviour, not something to
                // paper over here. selectableStrategies (used by `options`)
                // enforces both on whichever list is in use, so a list passed
                // in by a parent is held to the same rule as this one.
                criteria.addFilter(Criteria.equals('archivedAt', null));

                const versioned = new Criteria(1, 1);
                versioned.addAggregation(Criteria.terms(VERSIONED_AGGREGATION, 'strategyId'));

                const [result, versions] = await Promise.all([
                    this.repository.search(criteria, Shopware.Context.api),
                    this.versionRepository.search(versioned, Shopware.Context.api),
                ]);

                this.ownStrategies = sortStrategies([...result]);
                this.ownVersionedIds = versionedIds(versions.aggregations);

                if (!this.compact) {
                    await this.loadPrompt();
                }
            } catch (error) {
                this.error = error?.response?.data?.errors?.[0]?.detail ?? this.$tc('merchant-quote-agent.strategy.loadFailed');
            } finally {
                this.isLoading = false;
            }
        },

        async loadPrompt() {
            this.prompt = '';

            if (!this.value || !this.canRead) {
                return;
            }

            // Active only: a nightly proposal is a real row in this table with
            // `version = NULL` until a human accepts it, and this preview
            // promises the merchant the prompt their agent WILL send. Without
            // the filter that promise would rest on MySQL sorting NULLs last
            // under DESC. Mirrors StrategyResolver, which enforces the same
            // rule on the negotiation path itself.
            const criteria = new Criteria(1, 1);
            criteria.addFilter(Criteria.equals('strategyId', this.value));
            criteria.addFilter(Criteria.equals('status', 'active'));
            criteria.addSorting(Criteria.sort('version', 'DESC'));

            const result = await this.versionRepository.search(criteria, Shopware.Context.api);

            this.prompt = result.first()?.prompt ?? '';
        },

        // The watcher below fires loadPrompt() unawaited on selection change,
        // outside load()'s own try/catch. Without this wrapper a failing
        // version read would be an unhandled rejection: a silently blank
        // prompt and no banner, instead of the error surfaced here.
        async loadPromptSafely() {
            try {
                await this.loadPrompt();
            } catch (error) {
                this.error = error?.response?.data?.errors?.[0]?.detail ?? this.$tc('merchant-quote-agent.strategy.loadFailed');
            }
        },
    },
});
