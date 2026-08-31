import template from './merchant-quote-agent-list.html.twig';

const { Criteria } = Shopware.Data;

Shopware.Component.register('merchant-quote-agent-list', {
    template,

    inject: ['repositoryFactory', 'acl'],

    data() {
        return {
            decisions: null,
            isLoading: false,
            sortBy: 'createdAt',
            sortDirection: 'DESC',
            rangeDays: 30,
        };
    },

    computed: {
        decisionRepository() {
            return this.repositoryFactory.create('merchant_quote_agent_decision');
        },

        /** The range every query on this page shares, so the figures and the rows agree. */
        rangeFilter() {
            const from = new Date();
            from.setDate(from.getDate() - this.rangeDays);

            return Criteria.range('createdAt', { gte: from.toISOString() });
        },

        listCriteria() {
            const criteria = new Criteria(1, 25);
            criteria.addFilter(this.rangeFilter);
            criteria.addSorting(Criteria.sort(this.sortBy, this.sortDirection));

            return criteria;
        },

        columns() {
            return [
                { property: 'createdAt', label: 'merchant-quote-agent.list.columnCreatedAt', primary: true },
                { property: 'quoteNumber', label: 'merchant-quote-agent.list.columnQuoteNumber' },
                { property: 'outcome', label: 'merchant-quote-agent.list.columnOutcome' },
                { property: 'band', label: 'merchant-quote-agent.list.columnBand' },
                { property: 'discountPercentGranted', label: 'merchant-quote-agent.list.columnGranted' },
                { property: 'durationMs', label: 'merchant-quote-agent.list.columnDuration' },
                { property: 'escalationReason', label: 'merchant-quote-agent.list.columnEscalationReason' },
            ];
        },

        rangeOptions() {
            return [
                { value: 7, label: this.$tc('merchant-quote-agent.range.last7') },
                { value: 30, label: this.$tc('merchant-quote-agent.range.last30') },
                { value: 90, label: this.$tc('merchant-quote-agent.range.last90') },
            ];
        },
    },

    watch: {
        rangeDays() {
            this.load();
        },
    },

    created() {
        this.load();
    },

    methods: {
        async load() {
            this.isLoading = true;

            try {
                this.decisions = await this.decisionRepository.search(this.listCriteria, Shopware.Context.api);
            } finally {
                this.isLoading = false;
            }
        },
    },
});
