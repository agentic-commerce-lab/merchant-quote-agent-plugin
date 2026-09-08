import template from './merchant-quote-agent-list.html.twig';
import {
    ANSWERED_OUTCOMES,
    answeredTheBuyer,
    askSummary,
    dispositionVariant,
    escalationLabel,
    foldToQuotes,
    formatCurrency,
    formatDate,
    formatDateShort,
    formatPercent,
    outcomeLabel,
    outcomeVariant,
} from '../../decision';

const { Criteria } = Shopware.Data;

/**
 * ponytail: the page reads every pass in the period in one request and folds it
 * client-side, so the figures and the rows are one computation and cannot
 * disagree. The ceiling is PASS_LIMIT; past it the page says so rather than
 * quietly describing a subset. The upgrade path, if a shop ever services more
 * than this in 90 days, is a server-side latest-pass-per-quote read — DAL
 * `grouping` is not it: it returns the FIRST row per group regardless of
 * sorting and drops the total count.
 */
const PASS_LIMIT = 500;
const PAGE_SIZE = 25;

Shopware.Component.register('merchant-quote-agent-list', {
    template,

    inject: ['repositoryFactory', 'acl'],

    data() {
        return {
            passes: [],
            passTotal: 0,
            intake: null,
            isLoading: false,
            rangeDays: 30,
            dispositionFilter: 'all',
            page: 1,
            pendingDelete: null,
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

        /** One row per quote, newest activity first. */
        quotes() {
            return foldToQuotes(this.passes);
        },

        isTruncated() {
            return this.passTotal > PASS_LIMIT;
        },

        /**
         * The disposition partition. Every serviced quote is in exactly one
         * class, so these always sum to `quotes.length`.
         */
        partition() {
            return this.quotes.reduce((counts, quote) => {
                counts[quote.disposition] = (counts[quote.disposition] ?? 0) + 1;

                return counts;
            }, {});
        },

        needsReview() {
            return this.partition.needsReview ?? 0;
        },

        /**
         * Quotes the agent negotiated that the customer then ordered. The one
         * figure on this page that measures the agent earning money rather
         * than the agent being busy, so it is stated as a count and a share of
         * everything serviced.
         */
        orderPlaced() {
            return this.partition.orderPlaced ?? 0;
        },

        orderPlacedShare() {
            return this.quotes.length > 0 ? (this.orderPlaced / this.quotes.length) * 100 : 0;
        },

        /** Everything that is not waiting on the merchant, as one share. */
        needsReviewShare() {
            return this.quotes.length > 0 ? (this.needsReview / this.quotes.length) * 100 : 0;
        },

        /**
         * The classes other than the accented one, for the text breakdown.
         * `other` only appears when it has a member — it means an outcome this
         * page does not know, which is worth seeing rather than hiding.
         */
        restOfPartition() {
            return ['answered', 'awaitingBuyer', 'noAction', 'other']
                .map((key) => ({ key, count: this.partition[key] ?? 0 }))
                .filter((entry) => entry.key !== 'other' || entry.count > 0);
        },

        /** Net value of the quotes the agent touched, counted once per quote. */
        valueHandled() {
            return this.quotes.reduce((sum, quote) => sum + quote.netBefore, 0);
        },

        /** The currency is only claimed when the period has exactly one. */
        valueCurrency() {
            const seen = new Set(this.passes.map((pass) => pass.currencyIso).filter(Boolean));

            return seen.size === 1 ? [...seen][0] : null;
        },

        /** Averaged over the passes that granted something, not over all passes. */
        granted() {
            return this.averageOver(
                this.passes.filter((pass) => answeredTheBuyer(pass.outcome)),
                'discountPercentGranted',
            );
        },

        cap() {
            return this.averageOver(
                this.passes.filter((pass) => answeredTheBuyer(pass.outcome)),
                'maxDiscountPercent',
            );
        },

        /** How much of the allowed discount was actually spent. */
        capUsedShare() {
            if (this.granted === null || !this.cap) {
                return null;
            }

            return Math.min(100, (this.granted / this.cap) * 100);
        },

        filteredQuotes() {
            if (this.dispositionFilter === 'all') {
                return this.quotes;
            }

            return this.quotes.filter((quote) => quote.disposition === this.dispositionFilter);
        },

        pageCount() {
            return Math.max(1, Math.ceil(this.filteredQuotes.length / PAGE_SIZE));
        },

        pagedQuotes() {
            const start = (this.page - 1) * PAGE_SIZE;

            return this.filteredQuotes.slice(start, start + PAGE_SIZE);
        },

        pageSize() {
            return PAGE_SIZE;
        },

        /**
         * Only the narrow, fixed-content columns get a width. The ask and the
         * date share what is left: the ask is capped by `.mqa-ask` so it cannot
         * push the date out, and the date is allowed to wrap so it cannot clip
         * itself. Pinning all five to a pixel budget worked at exactly one
         * window width — a data-grid column is sized by its widest
         * unshrinkable content, so the fix has to make the content shrinkable
         * rather than guess the numbers.
         */
        columns() {
            return [
                { property: 'quoteNumber', label: 'merchant-quote-agent.list.columnQuoteNumber', primary: true, width: '110px' },
                { property: 'disposition', label: 'merchant-quote-agent.list.columnOutcome', width: '180px' },
                { property: 'asked', label: 'merchant-quote-agent.list.columnBuyerAsk' },
                { property: 'granted', label: 'merchant-quote-agent.list.columnMerchantOffer', width: '120px' },
                { property: 'lastActivity', label: 'merchant-quote-agent.list.columnCreatedAt' },
            ];
        },

        rangeOptions() {
            return [
                { value: 7, label: this.$tc('merchant-quote-agent.range.last7') },
                { value: 30, label: this.$tc('merchant-quote-agent.range.last30') },
                { value: 90, label: this.$tc('merchant-quote-agent.range.last90') },
            ];
        },

        /** Filters on where a quote stands now, which is what the rows show. */
        dispositionFilterOptions() {
            return [
                { value: 'all', label: this.$tc('merchant-quote-agent.list.filterAll') },
                ...['orderPlaced', 'needsReview', 'answered', 'awaitingBuyer', 'noAction'].map((key) => ({
                    value: key,
                    label: this.$tc(`merchant-quote-agent.disposition.${key}`),
                })),
            ];
        },
    },

    watch: {
        rangeDays() {
            this.page = 1;
            this.load();
        },

        dispositionFilter() {
            this.page = 1;
        },
    },

    created() {
        this.load();
    },

    methods: {
        formatCurrency,
        formatDate,
        formatDateShort,
        formatPercent,
        outcomeVariant,
        dispositionVariant,
        answeredTheBuyer,

        outcomeLabel(outcome) {
            return outcomeLabel(this, outcome);
        },

        escalationLabel(reason) {
            return escalationLabel(this, reason);
        },

        askSummary(asks) {
            return askSummary(this, asks);
        },

        dispositionLabel(key) {
            return this.$tc(`merchant-quote-agent.disposition.${key}`);
        },

        async load() {
            this.isLoading = true;

            try {
                await Promise.all([this.loadPasses(), this.loadIntake()]);
            } finally {
                this.isLoading = false;
            }
        },

        async loadPasses() {
            const criteria = new Criteria(1, PASS_LIMIT);
            criteria.addFilter(this.rangeFilter);
            // Newest first is what foldToQuotes needs to pick each quote's
            // current state.
            criteria.addSorting(Criteria.sort('createdAt', 'DESC'));
            criteria.setTotalCountMode(1);

            try {
                const result = await this.decisionRepository.search(criteria, Shopware.Context.api);

                this.passes = Array.from(result);
                this.passTotal = result.total ?? this.passes.length;
            } catch (error) {
                this.passes = [];
                this.passTotal = 0;
                // eslint-disable-next-line no-console
                console.error('merchant-quote-agent: failed to load servicing passes', error);
            }
        },

        /**
         * The quote-side figures. Separated because they read another plugin's
         * entity, which may be absent or unreadable while everything above
         * still works.
         */
        async loadIntake() {
            try {
                const quoteRepository = this.repositoryFactory.create('quote');

                const created = new Criteria(1, 1);
                created.addFilter(this.rangeFilter);
                created.addAggregation(Criteria.count('created', 'id'));

                const expired = new Criteria(1, 1);
                expired.addFilter(this.rangeFilter);
                expired.addFilter(Criteria.equals('stateMachineState.technicalName', 'expired'));
                expired.addAggregation(Criteria.terms('expiredQuotes', 'id'));

                const [createdResult, expiredResult] = await Promise.all([
                    quoteRepository.search(created, Shopware.Context.api),
                    quoteRepository.search(expired, Shopware.Context.api),
                ]);

                const answered = new Set(
                    this.passes.filter((pass) => answeredTheBuyer(pass.outcome)).map((pass) => pass.quoteId),
                );

                this.intake = {
                    created: createdResult.aggregations?.created?.count ?? 0,
                    expiredUnanswered: (expiredResult.aggregations?.expiredQuotes?.buckets ?? [])
                        .filter((bucket) => !answered.has(bucket.key)).length,
                };
            } catch (error) {
                // Nulled rather than zeroed: a viewer without `quote:read`
                // should see the figure absent, not see "0 created".
                this.intake = null;
                // eslint-disable-next-line no-console
                console.error('merchant-quote-agent: quote figures unavailable', error);
            }
        },

        averageOver(rows, field) {
            const values = rows
                .map((row) => row[field])
                .filter((value) => value !== null && value !== undefined)
                .map(Number);

            if (values.length === 0) {
                return null;
            }

            return values.reduce((sum, value) => sum + value, 0) / values.length;
        },

        /**
         * What the agent gave away on the quote's most recent pass. Gated on
         * the outcome rather than on the column being non-null: an escalated
         * pass can carry a recalculated total it never offered anyone.
         */
        grantedLabel(quote) {
            const pass = quote.latest;

            if (!answeredTheBuyer(pass.outcome)) {
                return '–';
            }

            if (pass.discountPercentGranted !== null && pass.discountPercentGranted !== undefined) {
                return formatPercent(pass.discountPercentGranted);
            }

            return pass.totalNetAfter !== null ? formatCurrency(pass.totalNetAfter, pass.currencyIso) : '–';
        },

        openQuote(quote) {
            this.$router.push({ name: 'merchant.quote.agent.detail', params: { id: quote.latest.id } });
        },

        onPageChange({ page }) {
            this.page = page;
        },

        /**
         * Deletes every pass recorded for one quote — the row's own unit. The
         * grid shows quotes, so offering to delete a single hidden pass would
         * not match what was clicked. Confirmed first: this is an audit trail
         * and the rows cannot be rebuilt.
         */
        async confirmDelete() {
            const quote = this.pendingDelete;

            if (!quote) {
                return;
            }

            const ids = this.passes
                .filter((pass) => pass.quoteId === quote.quoteId)
                .map((pass) => pass.id);

            this.pendingDelete = null;

            try {
                await this.decisionRepository.syncDeleted(ids, Shopware.Context.api);
                await this.load();
            } catch (error) {
                // eslint-disable-next-line no-console
                console.error('merchant-quote-agent: failed to delete audit rows', error);
            }
        },
    },
});
