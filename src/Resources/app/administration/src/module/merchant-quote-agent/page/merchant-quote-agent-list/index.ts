import template from './merchant-quote-agent-list.html.twig';
import {
    ANSWERED_OUTCOMES,
    answeredTheBuyer,
    askSummary,
    escalationLabel,
    formatCurrency,
    formatDate,
    formatPercent,
    outcomeLabel,
    outcomeVariant,
} from '../../decision';

const { Criteria } = Shopware.Data;

Shopware.Component.register('merchant-quote-agent-list', {
    template,

    inject: ['repositoryFactory', 'acl'],

    data() {
        return {
            decisions: null,
            figures: null,
            roundsByQuote: {},
            isLoading: false,
            sortBy: 'createdAt',
            sortDirection: 'DESC',
            rangeDays: 30,
            outcomeFilter: 'all',
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

            // The figures deliberately ignore this: they describe the period,
            // not the current view of it.
            const outcomes = this.outcomeFilterOptions.find((option) => option.value === this.outcomeFilter)?.outcomes;

            if (outcomes) {
                criteria.addFilter(Criteria.equalsAny('outcome', outcomes));
            }

            return criteria;
        },

        columns() {
            return [
                { property: 'quoteNumber', label: 'merchant-quote-agent.list.columnQuoteNumber', primary: true },
                { property: 'outcome', label: 'merchant-quote-agent.list.columnOutcome' },
                { property: 'interpretedAsks', label: 'merchant-quote-agent.list.columnBuyerAsk', sortable: false },
                { property: 'discountPercentGranted', label: 'merchant-quote-agent.list.columnMerchantOffer' },
                { property: 'createdAt', label: 'merchant-quote-agent.list.columnCreatedAt' },
            ];
        },

        rangeOptions() {
            return [
                { value: 7, label: this.$tc('merchant-quote-agent.range.last7') },
                { value: 30, label: this.$tc('merchant-quote-agent.range.last30') },
                { value: 90, label: this.$tc('merchant-quote-agent.range.last90') },
            ];
        },

        /**
         * `outcomes` is the filter each option applies; the default carries
         * none. Escalations lead because they are the only rows that need a
         * human, and there was previously no way to isolate them.
         */
        outcomeFilterOptions() {
            return [
                { value: 'all', label: this.$tc('merchant-quote-agent.list.filterAll') },
                { value: 'escalated', label: this.$tc('merchant-quote-agent.outcome.escalated'), outcomes: ['escalated'] },
                { value: 'answered', label: this.$tc('merchant-quote-agent.list.filterAnswered'), outcomes: ANSWERED_OUTCOMES },
                { value: 'clarified', label: this.$tc('merchant-quote-agent.outcome.clarified'), outcomes: ['clarified'] },
                { value: 'nothing_to_do', label: this.$tc('merchant-quote-agent.outcome.nothing_to_do'), outcomes: ['nothing_to_do'] },
            ];
        },
    },

    watch: {
        rangeDays() {
            this.load();
        },

        outcomeFilter() {
            this.loadDecisions();
        },
    },

    created() {
        this.load();
    },

    methods: {
        outcomeVariant,
        answeredTheBuyer,
        formatCurrency,
        formatDate,
        formatPercent,

        outcomeLabel(outcome) {
            return outcomeLabel(this, outcome);
        },

        escalationLabel(reason) {
            return escalationLabel(this, reason);
        },

        askSummary(asks) {
            return askSummary(this, asks);
        },

        async load() {
            await Promise.all([this.loadDecisions(), this.loadFigures()]);
        },

        async loadDecisions() {
            this.isLoading = true;

            try {
                this.decisions = await this.decisionRepository.search(this.listCriteria, Shopware.Context.api);
            } catch (error) {
                this.decisions = null;
                // eslint-disable-next-line no-console
                console.error('merchant-quote-agent: failed to load decisions', error);
            } finally {
                this.isLoading = false;
            }
        },

        async loadFigures() {
            try {
                const decisions = await this.aggregateDecisions();
                // How many passes each quote took, so a quote listed three
                // times reads as one three-round negotiation rather than as
                // three unrelated rows.
                this.roundsByQuote = decisions.rounds;

                this.figures = {
                    ...decisions,
                    // Nulled rather than zeroed when the quote entity is out of
                    // reach: a `merchant_quote_agent.viewer` without `quote:read`
                    // used to lose the whole card, and "0 received" would be a
                    // worse answer than "not available".
                    ...(await this.aggregateQuotes(decisions.answeredQuoteIds)),
                };
            } catch (error) {
                this.figures = null;
                this.roundsByQuote = {};
                // eslint-disable-next-line no-console
                console.error('merchant-quote-agent: failed to load figures', error);
            }
        },

        /** Three aggregate requests against the decisions of this period. */
        async aggregateDecisions() {
            const all = new Criteria(1, 1);
            all.addFilter(this.rangeFilter);
            all.addAggregation(
                Criteria.terms('perQuote', 'quoteId', null, null, Criteria.max('value', 'totalNetBefore')),
            );
            all.addAggregation(Criteria.terms('currencies', 'currencyIso'));

            const answered = new Criteria(1, 1);
            answered.addFilter(this.rangeFilter);
            answered.addFilter(Criteria.equalsAny('outcome', ANSWERED_OUTCOMES));
            answered.addAggregation(Criteria.terms('quotes', 'quoteId'));
            answered.addAggregation(Criteria.avg('granted', 'discountPercentGranted'));
            answered.addAggregation(Criteria.avg('cap', 'maxDiscountPercent'));

            const escalated = new Criteria(1, 1);
            escalated.addFilter(this.rangeFilter);
            escalated.addFilter(Criteria.equals('outcome', 'escalated'));
            escalated.addAggregation(Criteria.terms('quotes', 'quoteId'));

            const [allResult, answeredResult, escalatedResult] = await Promise.all([
                this.decisionRepository.search(all, Shopware.Context.api),
                this.decisionRepository.search(answered, Shopware.Context.api),
                this.decisionRepository.search(escalated, Shopware.Context.api),
            ]);

            const perQuote = allResult.aggregations?.perQuote?.buckets ?? [];
            const currencies = allResult.aggregations?.currencies?.buckets ?? [];
            const answeredQuoteIds = this.bucketKeys(answeredResult, 'quotes');

            return {
                // Every count here is a count of quotes, not of passes. Mixing
                // the two is what produced shares above 100%.
                handled: perQuote.length,
                answered: answeredQuoteIds.size,
                escalated: this.bucketKeys(escalatedResult, 'quotes').size,
                answeredQuoteIds,
                rounds: Object.fromEntries(perQuote.map((bucket) => [bucket.key, bucket.count])),
                valueHandled: perQuote.reduce((sum, bucket) => sum + Number(bucket.value?.max ?? 0), 0),
                // Summing across currencies would be a made-up number, so the
                // total only claims a currency when the period has exactly one.
                valueCurrency: currencies.length === 1 ? currencies[0].key : null,
                granted: answeredResult.aggregations?.granted?.avg ?? null,
                cap: answeredResult.aggregations?.cap?.avg ?? null,
            };
        },

        /**
         * The quote-side figures. Separated because they read another plugin's
         * entity, which may be absent or unreadable while everything above
         * still works.
         */
        async aggregateQuotes(answeredQuoteIds) {
            try {
                const quoteRepository = this.repositoryFactory.create('quote');

                const received = new Criteria(1, 1);
                received.addFilter(this.rangeFilter);
                received.addAggregation(Criteria.count('received', 'id'));

                const expired = new Criteria(1, 1);
                expired.addFilter(this.rangeFilter);
                expired.addFilter(Criteria.equals('stateMachineState.technicalName', 'expired'));
                expired.addAggregation(Criteria.terms('expiredQuotes', 'id'));

                const [receivedResult, expiredResult] = await Promise.all([
                    quoteRepository.search(received, Shopware.Context.api),
                    quoteRepository.search(expired, Shopware.Context.api),
                ]);

                return {
                    received: receivedResult.aggregations?.received?.count ?? 0,
                    expiredUnanswered: [...this.bucketKeys(expiredResult, 'expiredQuotes')].filter(
                        (id) => !answeredQuoteIds.has(id),
                    ).length,
                };
            } catch (error) {
                // eslint-disable-next-line no-console
                console.error('merchant-quote-agent: quote figures unavailable', error);

                return { received: null, expiredUnanswered: null };
            }
        },

        bucketKeys(result, name) {
            return new Set((result.aggregations?.[name]?.buckets ?? []).map((bucket) => bucket.key));
        },

        /**
         * Of the quotes the agent handled — not of the quotes received. Those
         * are different populations: a pass in this period can belong to a
         * quote created before it, which is how "400%" got on screen.
         */
        share(value) {
            const handled = this.figures?.handled ?? 0;

            return handled > 0 ? Math.round((value / handled) * 100) : null;
        },

        rounds(item) {
            return this.roundsByQuote[item.quoteId] ?? null;
        },

        /**
         * What the agent gave away. Gated on the outcome rather than on the
         * column being non-null: an escalated pass can carry a recalculated
         * total it never offered anyone.
         */
        grantedLabel(item) {
            if (!answeredTheBuyer(item.outcome)) {
                return '–';
            }

            if (item.discountPercentGranted !== null && item.discountPercentGranted !== undefined) {
                return formatPercent(item.discountPercentGranted);
            }

            return item.totalNetAfter !== null ? formatCurrency(item.totalNetAfter, item.currencyIso) : '–';
        },
    },
});
