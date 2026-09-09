import template from './merchant-quote-agent-list.html.twig';
import {
    answeredTheBuyer,
    askSummary,
    dispositionVariant,
    escalationLabel,
    foldToQuotes,
    formatCurrency,
    formatDate,
    formatDateShort,
    formatPercent,
    ORDER_PLACED_TERMINAL_STATE,
    outcomeLabel,
    outcomeVariant,
} from '../../decision';
import {
    autoExecutionRate,
    dealCycleTime,
    escalationResolution,
    formatSpan,
    priceRetention,
    splitDeals,
} from '../../measures';

const { Criteria } = Shopware.Data;

/**
 * ponytail: the page reads every pass in the period in one request and folds it
 * client-side, so the figures and the rows are one computation and cannot
 * disagree. The ceiling is PASS_LIMIT; past it the page says so rather than
 * quietly describing a subset. The upgrade path, if a shop ever services more
 * than this in 90 days, is a server-side latest-pass-per-quote read — DAL
 * `grouping` is not it: it returns the FIRST row per group regardless of
 * sorting and drops the total count.
 *
 * The read covers TWICE the selected range, because the auto-execution rate is
 * only meaningful as a trend and the previous equal-length window is the
 * comparison. The rows and every current-period figure filter to the recent
 * half. So the effective ceiling is half of PASS_LIMIT per window, which the
 * truncation banner already reports.
 */
const PASS_LIMIT = 500;

/**
 * Accepted quotes in the period, both agent-negotiated and not. Far smaller
 * than the pass read — most quotes never reach `accepted` — so this ceiling is
 * generous rather than tight.
 */
const QUOTE_LIMIT = 500;
const PAGE_SIZE = 25;

Shopware.Component.register('merchant-quote-agent-list', {
    template,

    inject: ['repositoryFactory', 'acl'],

    data() {
        return {
            passes: [],
            passTotal: 0,
            quoteRows: null,
            orderDates: new Map(),
            orderDatesUnavailable: false,
            slaHours: null,
            isLoading: false,
            rangeDays: 30,
            dispositionFilter: 'needsReview',
            page: 1,
            pendingDelete: null,
        };
    },

    computed: {
        decisionRepository() {
            return this.repositoryFactory.create('merchant_quote_agent_decision');
        },

        /** The start of the period the page describes. */
        windowStart() {
            const from = new Date();
            from.setDate(from.getDate() - this.rangeDays);

            return from;
        },

        /**
         * The range the PASS query shares — twice the period, so the trend has
         * a previous window to compare against. Quote-side queries use
         * `rangeFilter`, which is the period itself.
         */
        trendRangeFilter() {
            const from = new Date(this.windowStart);
            from.setDate(from.getDate() - this.rangeDays);

            return Criteria.range('createdAt', { gte: from.toISOString() });
        },

        /** The range every quote-side query on this page shares. */
        rangeFilter() {
            return Criteria.range('createdAt', { gte: this.windowStart.toISOString() });
        },

        /** The passes inside the period the page describes. */
        currentPasses() {
            const start = this.windowStart.getTime();

            return this.passes.filter((pass) => Date.parse(pass.createdAt) >= start);
        },

        /** The equal-length window before it, for the trend only. */
        previousPasses() {
            const start = this.windowStart.getTime();

            return this.passes.filter((pass) => Date.parse(pass.createdAt) < start);
        },

        /** One row per quote, newest activity first. */
        quotes() {
            return foldToQuotes(this.currentPasses);
        },

        quotesByQuoteId() {
            return new Map(this.quotes.map((quote) => [quote.quoteId, quote]));
        },

        isTruncated() {
            return this.passTotal > PASS_LIMIT;
        },

        autoExecution() {
            return autoExecutionRate(this.quotes);
        },

        /**
         * Movement against the previous equal-length window. Null when there
         * is nothing to compare against, so a first-week dashboard shows the
         * rate without inventing a trend for it.
         */
        autoExecutionDelta() {
            // A truncated read drops the OLDEST rows first (loadPasses sorts
            // newest-first), which are the previous window's — so past
            // PASS_LIMIT the previous window is a partial sample, not a
            // shorter one. Reporting a trend against it would be a specific,
            // wrong number rather than an absent one.
            if (this.isTruncated) {
                return null;
            }

            const previous = autoExecutionRate(foldToQuotes(this.previousPasses));

            if (previous.rate === null || this.autoExecution.rate === null) {
                return null;
            }

            return this.autoExecution.rate - previous.rate;
        },

        escalations() {
            return escalationResolution(this.currentPasses, this.slaHours);
        },

        /** The accepted quotes, split into the agent's and the untouched baseline. */
        deals() {
            return splitDeals(this.quoteRows ?? [], this.orderDates, this.quotesByQuoteId);
        },

        /** True when the quote read failed or never ran — tiles show unavailable, not zero. */
        dealsUnavailable() {
            return this.quoteRows === null;
        },

        retention() {
            return priceRetention(this.deals.agent, this.deals.baseline);
        },

        cycleTime() {
            return dealCycleTime(this.deals.agent, this.deals.baseline);
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
                ...['orderPlaced', 'needsReview', 'answered', 'awaitingBuyer', 'closedNoDeal', 'noAction'].map((key) => ({
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
        formatSpan,
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
                // The SLA and the passes are independent; the order dates need
                // the quote rows first, so those two are sequential.
                await Promise.all([
                    this.loadPasses(),
                    this.loadSla(),
                    this.loadQuotes().then(() => this.loadOrderDates()),
                ]);
            } finally {
                this.isLoading = false;
            }
        },

        async loadPasses() {
            const criteria = new Criteria(1, PASS_LIMIT);
            criteria.addFilter(this.trendRangeFilter);
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
         * The period's accepted quotes, agent-negotiated or not — the split is
         * by whether decision rows exist for them, which the page already
         * knows. Only accepted quotes matter: an unbought discount is not
         * realized and an unconfirmed deal has no cycle time.
         *
         * `requestedAt` and `totalLineItemDiscount` do not exist on
         * SwagCommercial 7.12. They are READ here and never filtered or sorted
         * on, so on 7.12 they arrive undefined and measures.ts falls back,
         * rather than the whole query failing.
         */
        async loadQuotes() {
            try {
                const quoteRepository = this.repositoryFactory.create('quote');

                const criteria = new Criteria(1, QUOTE_LIMIT);
                criteria.addFilter(this.rangeFilter);
                criteria.addFilter(Criteria.equals('stateMachineState.technicalName', ORDER_PLACED_TERMINAL_STATE));
                criteria.addSorting(Criteria.sort('createdAt', 'DESC'));

                const result = await quoteRepository.search(criteria, Shopware.Context.api);

                this.quoteRows = Array.from(result);
            } catch (error) {
                // Nulled rather than zeroed: a viewer without `quote:read`
                // should see the figures absent, not see a 0% discount and a
                // zero-day cycle.
                this.quoteRows = null;
                // eslint-disable-next-line no-console
                console.error('merchant-quote-agent: quote figures unavailable', error);
            }
        },

        /**
         * When each accepted quote's order was placed, which is the confirmed
         * end of the deal cycle.
         *
         * A second read rather than an association: `quote.order` is declared
         * WITHOUT ApiAware on both 7.12 and 7.13, so the admin API cannot
         * traverse it. `quote.orderId` is ApiAware, so the ids come from the
         * quote read and the dates from the core order repository.
         */
        async loadOrderDates() {
            const ids = (this.quoteRows ?? []).map((row) => row.orderId).filter(Boolean);

            if (ids.length === 0) {
                this.orderDates = new Map();
                this.orderDatesUnavailable = false;

                return;
            }

            try {
                const orderRepository = this.repositoryFactory.create('order');

                const criteria = new Criteria(1, ids.length);
                criteria.setIds(ids);

                const result = await orderRepository.search(criteria, Shopware.Context.api);

                this.orderDates = new Map(
                    Array.from(result).map((order) => [order.id, order.orderDateTime]),
                );
                this.orderDatesUnavailable = false;
            } catch (error) {
                // Emptied, not nulled: the quotes were readable, so every
                // other measure still stands. `orderDatesUnavailable` is what
                // the cycle-time tile checks instead, so a `order:read` 403
                // reads as "can't tell" rather than "genuinely no deals".
                this.orderDates = new Map();
                this.orderDatesUnavailable = true;
                // eslint-disable-next-line no-console
                console.error('merchant-quote-agent: order dates unavailable', error);
            }
        },

        /**
         * The escalation SLA, read straight from system config at global
         * scope. It benchmarks a figure on this page and steers nothing in the
         * pipeline, which is why it is not part of QuoteAgentSettings.
         */
        async loadSla() {
            try {
                const values = await Shopware.Service('systemConfigApiService')
                    .getValues('MerchantQuoteAgentPlugin.config');
                const value = values?.['MerchantQuoteAgentPlugin.config.escalationSlaHours'];

                this.slaHours = typeof value === 'number' && value > 0 ? value : null;
            } catch (error) {
                // No SLA means the tile reports the measured time with no
                // verdict, which is the same as a shop that left it blank.
                this.slaHours = null;
                // eslint-disable-next-line no-console
                console.error('merchant-quote-agent: escalation SLA unavailable', error);
            }
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
