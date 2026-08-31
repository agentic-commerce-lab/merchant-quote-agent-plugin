import template from './merchant-quote-agent-detail.html.twig';

Shopware.Component.register('merchant-quote-agent-detail', {
    template,

    inject: ['repositoryFactory', 'acl'],

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

        /** JSON columns rendered as readable pairs rather than a dumped blob. */
        entries(value) {
            if (value === null || value === undefined) {
                return [];
            }

            if (Array.isArray(value)) {
                return value.map((item, index) => ({
                    key: String(index),
                    value: typeof item === 'object' ? JSON.stringify(item) : String(item),
                }));
            }

            return Object.entries(value).map(([key, item]) => ({
                key,
                value: typeof item === 'object' ? JSON.stringify(item) : String(item),
            }));
        },
    },
});
