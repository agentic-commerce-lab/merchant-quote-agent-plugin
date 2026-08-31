import template from './merchant-quote-agent-list.html.twig';

const { Criteria } = Shopware.Data;

Shopware.Component.register('merchant-quote-agent-list', {
    template,

    inject: ['repositoryFactory', 'acl'],

    data() {
        return {
            decisions: null,
            figures: null,
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
                await this.loadFigures();
            } finally {
                this.isLoading = false;
            }
        },

        /** One aggregate request against the decisions, one against the quotes. */
        async loadFigures() {
            const [decisions, quotes] = await Promise.all([
                this.aggregateDecisions(),
                this.aggregateQuotes(),
            ]);

            const received = quotes.received;
            const handled = decisions.perQuote.buckets.length;

            this.figures = {
                received,
                handled,
                autoAnswered: decisions.autoAnswered,
                escalated: decisions.escalated,
                expiredUnanswered: quotes.expired - decisions.answeredQuoteIds.size,
                valueHandled: decisions.perQuote.buckets.reduce(
                    (sum, bucket) => sum + Number(bucket.value?.max ?? 0),
                    0,
                ),
                granted: decisions.granted,
                cap: decisions.cap,
            };
        },

        async aggregateDecisions() {
            const criteria = new Criteria(1, 1);
            criteria.addFilter(this.rangeFilter);
            criteria.addAggregation(
                Criteria.terms('perQuote', 'quoteId', null, null, Criteria.max('value', 'totalNetBefore')),
            );

            const answered = new Criteria(1, 1);
            answered.addFilter(this.rangeFilter);
            answered.addFilter(Criteria.equalsAny('outcome', ['offered', 'countered']));
            answered.addAggregation(Criteria.count('answered', 'id'));
            answered.addAggregation(Criteria.terms('answeredQuotes', 'quoteId'));
            answered.addAggregation(Criteria.avg('granted', 'discountPercentGranted'));
            answered.addAggregation(Criteria.avg('cap', 'maxDiscountPercent'));

            const escalated = new Criteria(1, 1);
            escalated.addFilter(this.rangeFilter);
            escalated.addFilter(Criteria.equals('outcome', 'escalated'));
            escalated.addAggregation(Criteria.count('escalated', 'id'));

            const [all, answeredResult, escalatedResult] = await Promise.all([
                this.decisionRepository.aggregate(criteria, Shopware.Context.api),
                this.decisionRepository.aggregate(answered, Shopware.Context.api),
                this.decisionRepository.aggregate(escalated, Shopware.Context.api),
            ]);

            return {
                perQuote: all.perQuote,
                autoAnswered: answeredResult.answered?.count ?? 0,
                escalated: escalatedResult.escalated?.count ?? 0,
                granted: answeredResult.granted?.avg ?? null,
                cap: answeredResult.cap?.avg ?? null,
                answeredQuoteIds: new Set(
                    (answeredResult.answeredQuotes?.buckets ?? []).map((bucket) => bucket.key),
                ),
            };
        },

        async aggregateQuotes() {
            const quoteRepository = this.repositoryFactory.create('quote');

            const received = new Criteria(1, 1);
            received.addFilter(this.rangeFilter);
            received.addAggregation(Criteria.count('received', 'id'));

            const expired = new Criteria(1, 1);
            expired.addFilter(this.rangeFilter);
            expired.addFilter(Criteria.equals('stateMachineState.technicalName', 'expired'));
            expired.addAggregation(Criteria.count('expired', 'id'));

            const [receivedResult, expiredResult] = await Promise.all([
                quoteRepository.aggregate(received, Shopware.Context.api),
                quoteRepository.aggregate(expired, Shopware.Context.api),
            ]);

            return {
                received: receivedResult.received?.count ?? 0,
                expired: expiredResult.expired?.count ?? 0,
            };
        },

        share(value) {
            const received = this.figures?.received ?? 0;

            return received > 0 ? Math.round((value / received) * 100) : null;
        },
    },
});
