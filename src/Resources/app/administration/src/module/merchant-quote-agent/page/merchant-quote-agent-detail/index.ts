import template from './merchant-quote-agent-detail.html.twig';
import { historySummary, historyReads } from '../../history';
import {
    ORDER_PLACED_TERMINAL_STATE,
    answeredTheBuyer,
    askItems,
    bandVariant,
    conversation,
    escalationExplanation,
    escalationLabel,
    formatCurrency,
    formatDate,
    formatDuration,
    formatPercent,
    mergeStream,
    outcomeLabel,
    outcomeVariant,
    passNotes,
    terminalExplanation,
    terminalLabel,
    triggerLabel,
    writeLabels,
} from '../../decision';

const { Criteria } = Shopware.Data;

Shopware.Component.register('merchant-quote-agent-detail', {
    template,

    inject: ['repositoryFactory'],

    data() {
        return {
            record: null,
            rounds: [],
            quote: null,
            isLoading: false,
        };
    },

    computed: {
        decisionRepository() {
            return this.repositoryFactory.create('merchant_quote_agent_decision');
        },

        quoteRepository() {
            return this.repositoryFactory.create('quote');
        },

        /**
         * What the customer actually wrote, next to what the agent made of it.
         * Empty when the quote could not be read — Commercial absent, or the
         * role without `quote_comment:read` — and the card hides itself rather
         * than claiming the conversation was empty.
         */
        messages() {
            return conversation(this.quote?.comments ?? []);
        },

        /**
         * The order the customer placed, when they did.
         *
         * Route-guarded like `quoteRoute`: an id with nowhere to go is not a
         * link. `sw.order.detail` is core rather than Commercial, so this is
         * about the route existing at all, not about which plugins are on.
         */
        orderRoute() {
            if (!this.quote?.orderId || !this.$router.hasRoute?.('sw.order.detail')) {
                return null;
            }

            return { name: 'sw.order.detail', params: { id: this.quote.orderId } };
        },

        /** The success this whole module exists to produce. */
        orderPlaced() {
            return this.terminalState === ORDER_PLACED_TERMINAL_STATE;
        },

        quoteNumber() {
            return this.record?.quoteNumber ?? '–';
        },

        totalRounds() {
            return this.runs.length;
        },

        /** Passes that put something in front of the buyer. */
        answeredRounds() {
            return this.runs.filter((run) => run.answered).length;
        },

        runs() {
            const list = this.rounds.length > 0 ? this.rounds : (this.record ? [this.record] : []);

            return list.map((round, index) => this.formatRun(round, index));
        },

        /**
         * One sequence: the buyer's comments, the passes that answered them,
         * and the outcome that ended the negotiation. See `mergeStream()` for
         * why the agent's own comments are not entries of their own.
         */
        stream() {
            return mergeStream(this.messages, this.runs, { state: this.terminalState, at: this.terminalAt });
        },

        /**
         * The last pass that actually made an offer — not simply the last pass.
         * The summary used to read the latest one, so a negotiation whose most
         * recent pass escalated reported no offer at all.
         */
        latestOffer() {
            return [...this.runs].reverse().find((run) => run.answered) ?? null;
        },

        lastActivity() {
            return this.runs.length > 0 ? this.runs[this.runs.length - 1].raw.createdAt : null;
        },

        terminalState() {
            return this.runs.find((run) => run.raw.terminalState)?.raw.terminalState ?? null;
        },

        terminalAt() {
            return this.runs.find((run) => run.raw.terminalAt)?.raw.terminalAt ?? null;
        },

        /** No terminal state means the quote has not finished, whatever the last pass did. */
        isInFlight() {
            return this.terminalState === null;
        },

        statusLabel() {
            if (this.terminalState !== null) {
                return terminalLabel(this, this.terminalState);
            }

            const last = this.runs[this.runs.length - 1];

            return last ? last.outcomeLabel : '–';
        },

        statusVariant() {
            if (this.terminalState !== null) {
                return this.orderPlaced ? 'positive' : 'neutral';
            }

            return this.runs[this.runs.length - 1]?.outcomeVariant ?? 'neutral';
        },

        /**
         * The quote this record is about. Reading that a pass needs review is
         * only useful if you can then go and act on it, and this page had no
         * way out of itself.
         *
         * Route-guarded rather than assumed: `sw.quote.detail` belongs to
         * Commercial's B2B quote management, which need not be installed for
         * this plugin's own pages to work.
         */
        quoteRoute() {
            if (!this.record?.quoteId || !this.$router.hasRoute?.('sw.quote.detail')) {
                return null;
            }

            return { name: 'sw.quote.detail', params: { id: this.record.quoteId } };
        },
    },

    created() {
        this.load();
    },

    methods: {
        formatCurrency,
        formatDate,
        formatPercent,

        /** The outcome entry that closes the stream: the state, then why. */
        terminalTitle(state) {
            return terminalLabel(this, state);
        },

        terminalWhy(state) {
            return terminalExplanation(this, state);
        },

        async load() {
            this.isLoading = true;

            try {
                this.record = await this.decisionRepository.get(this.$route.params.id, Shopware.Context.api);

                // Grouped on quoteId, the actual key. quoteNumber is nullable,
                // and a record without one used to show as a lone round even
                // when its quote had several.
                if (this.record?.quoteId) {
                    const criteria = new Criteria(1, 50);
                    criteria.addFilter(Criteria.equals('quoteId', this.record.quoteId));
                    criteria.addSorting(Criteria.sort('createdAt', 'ASC'));

                    this.rounds = Array.from(await this.decisionRepository.search(criteria, Shopware.Context.api));
                    this.quote = await this.loadQuote(this.record.quoteId);
                }
            } catch (error) {
                this.record = null;
                this.rounds = [];
                this.quote = null;
                // eslint-disable-next-line no-console
                console.error('merchant-quote-agent: failed to load decision flow', error);
            } finally {
                this.isLoading = false;
            }
        },

        /**
         * The quote itself, for the customer's messages and the order they
         * placed. Caught separately from the record load and on its own: the
         * quote entity belongs to Commercial's B2B quote management, which
         * need not be installed, and the role may hold
         * `merchant_quote_agent_decision:read` without `quote_comment:read`.
         * Neither is a reason to show "Decision not found" for a record that
         * loaded perfectly well, so this degrades to no quote and the cards
         * that need one hide themselves.
         */
        async loadQuote(quoteId) {
            try {
                const criteria = new Criteria(1, 1);
                criteria.addAssociation('comments');

                return await this.quoteRepository.get(quoteId, Shopware.Context.api, criteria);
            } catch (error) {
                // eslint-disable-next-line no-console
                console.warn('merchant-quote-agent: the quote behind this record could not be read', error);

                return null;
            }
        },

        /**
         * One servicing pass, ready to render.
         *
         * The title comes from why the pass ran, not from its position: a pass
         * numbered three is not therefore a buyer counter-offer, and labelling
         * it as one described conversations that never happened.
         */
        formatRun(round, index) {
            const answered = answeredTheBuyer(round.outcome);

            return {
                id: round.id,
                index: index + 1,
                raw: round,
                answered,
                // A state-change pass with nothing to answer carries no ask, no
                // band and no model. It gets one line instead of the same card
                // as a round that negotiated.
                isNoop: !answered && round.outcome === 'nothing_to_do',
                title: triggerLabel(this, round.triggerReason),
                timestamp: formatDate(round.createdAt),
                outcomeLabel: outcomeLabel(this, round.outcome),
                outcomeVariant: outcomeVariant(round.outcome),
                asks: askItems(this, round.interpretedAsks),
                band: round.band,
                bandVariant: bandVariant(round.band),
                cap: formatPercent(round.maxDiscountPercent),
                granted: answered ? formatPercent(round.discountPercentGranted) : null,
                totals: answered && round.totalNetBefore !== null && round.totalNetAfter !== null
                    ? `${formatCurrency(round.totalNetBefore, round.currencyIso)} → ${formatCurrency(round.totalNetAfter, round.currencyIso)}`
                    : null,
                // What the pass actually did to the quote, and what a person
                // still has to look at. Both were recorded from the start and
                // both sat in the collapsed technical fold.
                changes: writeLabels(this, round.writes),
                notes: passNotes(this, round),
                escalationReason: round.escalationReason ? escalationLabel(this, round.escalationReason) : null,
                escalationWhy: escalationExplanation(this, round),
                // The specifics behind the sentence: the rule that was broken,
                // in the words the pipeline recorded. Only shown for a pass
                // that escalated — on a pass that succeeded, `violations` is
                // the verifier's empty result and means nothing to a merchant.
                escalationDetail: round.escalationReason && Array.isArray(round.violations) && round.violations.length > 0
                    ? round.violations.join('; ')
                    : null,
                // The buyer's own words are not recorded anywhere; their ask
                // survives only as `interpretedAsks`, rendered above. The
                // conversation card reads them off the quote instead.
                reply: round.replyToBuyer || null,
                technical: this.technical(round),
            };
        },

        /**
         * Everything recorded for triage rather than for reading: the model
         * call, the safety assertions, what was written, and what failed. All
         * of it was already in the table and none of it was on the page.
         */
        technical(round) {
            const rows = [
                { key: 'trigger', value: triggerLabel(this, round.triggerReason) },
                { key: 'attempt', value: round.attempt !== null ? String(round.attempt) : '–' },
                { key: 'model', value: round.model || '–' },
                { key: 'modelHost', value: round.modelHost || '–' },
                {
                    key: 'tokens',
                    value: round.promptTokens !== null || round.completionTokens !== null
                        ? `${round.promptTokens ?? '–'} / ${round.completionTokens ?? '–'}`
                        : '–',
                },
                { key: 'modelLatency', value: formatDuration(round.modelLatencyMs) },
                { key: 'duration', value: formatDuration(round.durationMs) },
                { key: 'authorized', value: this.bool(round.authorized) },
                { key: 'verified', value: this.bool(round.verified) },
                { key: 'customer', value: typeof round.customerId === 'string' && round.customerId ? round.customerId : '–', mono: true },
                { key: 'accountHistory', value: historySummary(this, round.historyReads), wide: true },
                { key: 'historyReads', value: historyReads(this, round.historyReads), wide: true },
                { key: 'revision', value: round.revisionVersionId || '–', mono: true },
                {
                    key: 'promptHashes',
                    value: [round.extractPromptHash, round.negotiatePromptHash, round.replyPromptHash]
                        .map((hash) => (hash ? hash.slice(0, 12) : '–'))
                        .join(' / '),
                    mono: true,
                },
            ];

            // The model's own answer, recorded on every pass since the table
            // existed and rendered nowhere. Wide because it is JSON: in a
            // 200px grid cell it reads as a column of punctuation.
            if (round.rawProposal) {
                rows.push({ key: 'rawProposal', value: round.rawProposal, mono: true, wide: true });
            }

            if (round.errorClass) {
                rows.push({ key: 'errorClass', value: round.errorClass, mono: true });
            }

            if (Array.isArray(round.errorChain) && round.errorChain.length > 0) {
                rows.push({
                    key: 'errorChain',
                    value: round.errorChain
                        .map((link) => Object.values(link).filter(Boolean).join(': '))
                        .join(' ← '),
                    mono: true,
                });
            }

            return rows.map((row) => ({
                label: this.$tc(`merchant-quote-agent.tech.${row.key}`),
                value: row.value,
                mono: row.mono === true,
                wide: row.wide === true,
            }));
        },

        /** null is not false here: it means the pass never got far enough to assert. */
        bool(value) {
            if (value === null || value === undefined) {
                return '–';
            }

            return this.$tc(`merchant-quote-agent.tech.${value ? 'yes' : 'no'}`);
        },
    },
});
