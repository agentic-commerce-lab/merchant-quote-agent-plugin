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
    },

    emits: ['update:value'],

    data() {
        return {
            strategies: [],
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

        options() {
            return this.strategies.map((strategy) => ({
                value: strategy.id,
                label: this.displayName(strategy),
            }));
        },

        selected() {
            return this.strategies.find((strategy) => strategy.id === this.value) ?? null;
        },

        selectedIsBuiltIn() {
            return isBuiltIn(this.value);
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
            this.loadPromptSafely();
        },
    },

    created() {
        this.load();
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
                // paper over here.
                criteria.addFilter(Criteria.equals('archivedAt', null));

                const versioned = new Criteria(1, 1);
                versioned.addAggregation(Criteria.terms(VERSIONED_AGGREGATION, 'strategyId'));

                const [result, versions] = await Promise.all([
                    this.repository.search(criteria, Shopware.Context.api),
                    this.versionRepository.search(versioned, Shopware.Context.api),
                ]);

                this.strategies = selectableStrategies(sortStrategies([...result]), versionedIds(versions.aggregations));
                await this.loadPrompt();
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

            const criteria = new Criteria(1, 1);
            criteria.addFilter(Criteria.equals('strategyId', this.value));
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
