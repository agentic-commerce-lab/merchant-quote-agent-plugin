import template from './merchant-quote-agent-detail.html.twig';

Shopware.Component.register('merchant-quote-agent-detail', {
    template,

    inject: ['repositoryFactory'],

    data() {
        return {
            record: null,
            isLoading: false,
        };
    },

    computed: {
        decisionRepository() {
            return this.repositoryFactory.create('merchant_quote_agent_decision');
        },
    },

    created() {
        this.load();
    },

    methods: {
        async load() {
            this.isLoading = true;

            try {
                this.record = await this.decisionRepository.get(this.$route.params.id, Shopware.Context.api);
            } catch (error) {
                // A rejection here must not vanish silently: it leaves the page
                // blank behind sw-card-view's v-if="record" with no visible sign
                // anything failed.
                // eslint-disable-next-line no-console
                console.error('merchant-quote-agent: failed to load decision', error);
            } finally {
                this.isLoading = false;
            }
        },

        /**
         * A JSON column as readable "path -> value" pairs. Nested objects become
         * dotted paths; nulls and empty containers are dropped rather than rendered
         * as "null" or "[]", which is noise to a merchant reading a decision.
         */
        flatten(value, prefix = '') {
            if (value === null || value === undefined) {
                return [];
            }

            if (Array.isArray(value)) {
                return value.flatMap((item, index) => this.flatten(item, `${prefix}[${index}]`));
            }

            if (typeof value === 'object') {
                return Object.entries(value).flatMap(
                    ([key, item]) => this.flatten(item, prefix ? `${prefix}.${key}` : key),
                );
            }

            return [{ key: prefix, value: String(value) }];
        },

        /** errorChain entries as class/message pairs; `at` (an internal file path) is dropped. */
        errorChainEntries(value) {
            if (!Array.isArray(value)) {
                return [];
            }

            return value.map((item, index) => ({
                key: String(index),
                errorClass: this.dash(item?.class),
                message: this.dash(item?.message),
            }));
        },

        /** The consistent fallback for a nullable field: an em dash, never a bare blank. */
        dash(value) {
            return value === null || value === undefined || value === '' ? '–' : value;
        },

        /**
         * The five terminal quote states in the merchant's language. $tc
         * returns the key itself when no snippet matches, which would render as
         * a dotted path — so a state Shopware adds later shows its technical
         * name instead, which is wrong-looking but readable.
         */
        terminalLabel(state) {
            const key = `merchant-quote-agent.detail.terminal.${state}`;
            const label = this.$tc(key);

            return label === key ? state : label;
        },

        /** A timestamp a merchant can read, not an ISO string. */
        formatDate(value) {
            const dateFilter = Shopware.Filter?.getByName?.('date');

            return dateFilter ? dateFilter(value) : String(value);
        },
    },
});
