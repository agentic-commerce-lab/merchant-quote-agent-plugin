import template from './merchant-quote-agent-list.html.twig';
import {
    answeredNetAfter,
    answeredTheBuyer,
    askSummary,
    dispositionVariant,
    escalationLabel,
    foldToQuotes,
    formatCurrency,
    formatDate,
    formatDateShort,
    formatPercent,
    humanReviewRequests,
    ORDER_PLACED_TERMINAL_STATE,
    outcomeLabel,
    outcomeVariant,
    quoteDiscountPercent,
} from '../../decision';
import {
    autoExecutionRate,
    dealCycleTime,
    escalationResolution,
    formatSpan,
    priceRetention,
    splitDeals,
} from '../../measures';
import { strategyRows } from '../../strategy-measures';
import { ASSIGNMENT_SOURCE_SNIPPET_KEYS } from '../../assignment.ts';

const { Criteria } = Shopware.Data;

/**
 * Every row a criteria matches, up to MAX_READ_PAGES pages. The caller's
 * criteria is paged in place; `total` is the full match count, so a caller can
 * tell a capped read from a complete one.
 */
async function searchAll(repository, criteria) {
    criteria.setLimit(READ_PAGE_SIZE);
    criteria.setTotalCountMode(1);

    const rows = [];
    let total = 0;

    for (let page = 1; page <= MAX_READ_PAGES; page += 1) {
        criteria.setPage(page);

        // eslint-disable-next-line no-await-in-loop
        const result = await repository.search(criteria, Shopware.Context.api);

        rows.push(...Array.from(result));
        total = result.total ?? rows.length;

        if (rows.length >= total || result.length < READ_PAGE_SIZE) {
            break;
        }
    }

    return { rows, total };
}

interface PriceQuote {
    netBefore: number | null;
    latest: {
        outcome: string | null;
        reviewStatus?: string | null;
        discountPercentGranted?: number | null;
        totalNetAfter?: number | null;
        currencyIso: string;
        sentChanges?: { totalNet?: number | null } | null;
    };
}

/**
 * ponytail: the page reads every pass in the period and folds it client-side,
 * so the figures and the rows are one computation and cannot disagree. One
 * request returns at most READ_PAGE_SIZE rows (the admin API's own ceiling), so
 * `searchAll` walks the pages; MAX_READ_PAGES bounds that walk. Past it the
 * page says so rather than quietly describing a subset. The upgrade path, if a
 * shop ever services more than this in 90 days, is a server-side
 * latest-pass-per-quote read — DAL `grouping` is not it: it returns the FIRST
 * row per group regardless of sorting and drops the total count.
 *
 * The pass read covers TWICE the selected range, because the auto-execution
 * rate is only meaningful as a trend and the previous equal-length window is
 * the comparison. The rows and every current-period figure filter to the
 * recent half.
 */
const READ_PAGE_SIZE = 500;
const MAX_READ_PAGES = 20;
const PAGE_SIZE = 25;

Shopware.Component.register('merchant-quote-agent-list', {
    template,

    inject: ['repositoryFactory', 'syncService', 'acl'],

    mixins: [Shopware.Mixin.getByName('notification')],

    data() {
        return {
            passes: [],
            passTotal: 0,
            quoteRows: null,
            orderDates: new Map(),
            orderDatesUnavailable: false,
            slaHours: null,
            strategyVersions: null,
            strategies: null,
            isLoading: false,
            rangeDays: 30,
            dispositionFilter: 'needsReview',
            page: 1,
            pendingDelete: null,
            isExporting: false,
        };
    },

    computed: {
        decisionRepository() {
            return this.repositoryFactory.create('merchant_quote_agent_decision');
        },

        strategyVersionRepository() {
            return this.repositoryFactory.create('merchant_quote_agent_strategy_version');
        },

        strategyRepository() {
            return this.repositoryFactory.create('merchant_quote_agent_strategy');
        },

        httpClient() {
            return this.syncService.httpClient;
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
            return this.passTotal > this.passes.length;
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
            // MAX_READ_PAGES the previous window is a partial sample, not a
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

        /**
         * The same success measures as the tiles above, one row per
         * negotiation strategy. Sorted by name rather than by any figure —
         * this table states no winner, and a score-sorted row order would
         * imply one regardless of what the template does with it.
         *
         * Null-named (unattributed) rows sort last: "no name" is not the
         * empty string and should not win a lexical sort against real ones.
         */
        strategyComparison() {
            const rows = strategyRows(
                this.currentPasses,
                this.quoteRows ?? [],
                this.orderDates,
                this.slaHours,
                (id) => this.strategyOf(id),
            );

            return [...rows].sort((a, b) => {
                if (a.name === null || b.name === null) {
                    return (a.name === null ? 1 : 0) - (b.name === null ? 1 : 0);
                }

                return a.name.localeCompare(b.name);
            });
        },

        strategyColumns() {
            return [
                { property: 'name', label: 'merchant-quote-agent.strategyComparison.columnStrategy', primary: true, multiLine: true },
                { property: 'quotes', label: 'merchant-quote-agent.strategyComparison.columnQuotes', width: '90px', multiLine: true },
                { property: 'autoExecution', label: 'merchant-quote-agent.strategyComparison.columnAutoExecution', multiLine: true },
                { property: 'escalations', label: 'merchant-quote-agent.strategyComparison.columnResolution', multiLine: true },
                { property: 'priceRetention', label: 'merchant-quote-agent.strategyComparison.columnRetention', multiLine: true },
                { property: 'cycleTime', label: 'merchant-quote-agent.strategyComparison.columnCycleTime', multiLine: true },
                { property: 'tokens', label: 'merchant-quote-agent.strategyComparison.columnTokens', multiLine: true },
            ];
        },

        filteredQuotes() {
            if (this.dispositionFilter === 'all') {
                return this.quotes;
            }

            return this.quotes.filter((quote) => quote.disposition === this.dispositionFilter);
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
                ...['orderPlaced', 'needsReview', 'awaitingReview', 'answered', 'awaitingBuyer', 'closedNoDeal', 'noAction'].map((key) => ({
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
        answeredNetAfter,
        formatCurrency,
        formatDate,
        formatDateShort,
        formatPercent,
        formatSpan,
        outcomeVariant,
        dispositionVariant,
        answeredTheBuyer,
        humanReviewRequests,

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

        /**
         * Which rung of the assignment ladder — pin, rule, split, config —
         * chose a strategy, translated rather than shown as the raw enum
         * value the row's `assignment` spread carries.
         *
         * The map itself lives in assignment.ts, not here: assignment.check.mjs
         * pins it end to end against the PHP enum and both locale files, so a
         * fifth source can no longer render as a raw, untranslated value with
         * nothing failing -- a second literal copy in this file would have
         * been exactly the kind of drift that check exists to catch.
         */
        assignmentSourceLabel(source) {
            const key = ASSIGNMENT_SOURCE_SNIPPET_KEYS[source];

            return key ? this.$tc(`merchant-quote-agent.strategyComparison.${key}`) : source;
        },

        /**
         * A version id resolved to the strategy it belongs to: its strategy id, the
         * strategy's display name, and the version's own number — via the version's
         * strategyId.
         *
         * Two reads rather than an association, because StrategyVersion.strategyId is a
         * plain UUID column by design — see that entity's docblock.
         *
         * An unknown id returns null rather than a placeholder: the template decides
         * how an unnamed group reads, and a name invented here would be
         * indistinguishable from a real one.
         */
        strategyOf(strategyVersionId) {
            if (strategyVersionId === null) {
                return null;
            }

            const version = (this.strategyVersions ?? []).find((row) => row.id === strategyVersionId);

            if (!version) {
                return null;
            }

            const name = (this.strategies ?? []).find((row) => row.id === version.strategyId)?.name ?? null;

            return { strategyId: version.strategyId, name, version: version.version };
        },

        /**
         * Download the selected period as anonymized JSONL, for sending to
         * Shopware.
         *
         * The period is the one the smart bar's select scopes, and the end is
         * NOW rather than an open range: the endpoint's range is half-open, so
         * an end is required, and a record written between this line and the
         * query belongs in the next export rather than this one.
         *
         * `responseType: 'blob'` and an object URL rather than a plain link,
         * because the admin API needs the Authorization header and a browser
         * navigation cannot carry one. The filename comes from the response's
         * own Content-Disposition, so a `curl` of the endpoint and a click
         * here produce the same file under the same name.
         */
        async exportDecisions(withComments: boolean) {
            this.isExporting = true;

            try {
                const response = await this.httpClient.get('_action/merchant-quote-agent/decision-export', {
                    params: {
                        from: this.windowStart.toISOString(),
                        to: new Date().toISOString(),
                        comments: withComments ? '1' : '0',
                    },
                    headers: this.syncService.getBasicHeaders(),
                    responseType: 'blob',
                });

                const disposition = response.headers?.['content-disposition'] ?? '';
                const name = /filename="([^"]+)"/.exec(disposition)?.[1] ?? 'merchant-quote-agent.jsonl';
                const url = URL.createObjectURL(response.data);
                const link = document.createElement('a');

                link.href = url;
                link.download = name;
                link.click();
                URL.revokeObjectURL(url);
            } catch (error) {
                // ponytail: one message for every failure. The endpoint's own
                // 400s describe a range this button cannot produce -- it
                // computes both dates -- and under `responseType: 'blob'` the
                // body is a Blob that would have to be read and parsed before
                // it could be shown. If a failure a merchant can act on ever
                // appears here, read `error.response.data.text()` then.
                this.createNotificationError({ message: this.$tc('merchant-quote-agent.export.failed') });
                // eslint-disable-next-line no-console
                console.error('merchant-quote-agent: export failed', error);
            } finally {
                this.isExporting = false;
            }
        },

        async load() {
            this.isLoading = true;

            try {
                // The SLA and the passes are independent; the order dates need
                // the quote rows first, so those two are sequential.
                await Promise.all([
                    this.loadPasses(),
                    this.loadStrategies(),
                    this.loadSla(),
                    this.loadQuotes().then(() => this.loadOrderDates()),
                ]);
            } finally {
                this.isLoading = false;
            }
        },

        async loadPasses() {
            const criteria = new Criteria();
            criteria.addFilter(this.trendRangeFilter);
            // Newest first is what foldToQuotes needs to pick each quote's
            // current state.
            criteria.addSorting(Criteria.sort('createdAt', 'DESC'));

            try {
                const { rows, total } = await searchAll(this.decisionRepository, criteria);

                this.passes = rows;
                this.passTotal = total;
            } catch (error) {
                this.passes = [];
                this.passTotal = 0;
                // eslint-disable-next-line no-console
                console.error('merchant-quote-agent: failed to load servicing passes', error);
            }
        },

        /**
         * The strategy versions and strategies behind the passes, so
         * `strategyOf` can resolve a version id to its strategy.
         *
         * Nulled rather than left stale on failure: a merchant without the
         * strategy ACL privilege should get absent names, not a broken page.
         */
        async loadStrategies() {
            try {
                const [versions, strategies] = await Promise.all([
                    this.strategyVersionRepository.search(new Criteria(1, 500), Shopware.Context.api),
                    this.strategyRepository.search(new Criteria(1, 500), Shopware.Context.api),
                ]);

                this.strategyVersions = Array.from(versions);
                this.strategies = Array.from(strategies);
            } catch (error) {
                this.strategyVersions = null;
                this.strategies = null;
                // eslint-disable-next-line no-console
                console.error('merchant-quote-agent: strategy names unavailable', error);
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

                const criteria = new Criteria();
                criteria.addFilter(this.rangeFilter);
                criteria.addFilter(Criteria.equals('stateMachineState.technicalName', ORDER_PLACED_TERMINAL_STATE));
                criteria.addSorting(Criteria.sort('createdAt', 'DESC'));

                this.quoteRows = (await searchAll(quoteRepository, criteria)).rows;
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
        grantedLabel(quote: PriceQuote) {
            const pass = quote.latest;

            if (!answeredTheBuyer(pass.outcome, pass.reviewStatus ?? null)) {
                return '–';
            }

            if (pass.reviewStatus === 'sent') {
                const actual = answeredNetAfter(pass);
                const reduction = quoteDiscountPercent(quote.netBefore, actual);

                return reduction !== null ? formatPercent(reduction) : actual !== null ? formatCurrency(actual, pass.currencyIso) : '–';
            }

            if (pass.discountPercentGranted !== null && pass.discountPercentGranted !== undefined) {
                return formatPercent(pass.discountPercentGranted);
            }

            const net = answeredNetAfter(pass);

            return net !== null ? formatCurrency(net, pass.currencyIso) : '–';
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
