import template from './merchant-quote-agent-detail.html.twig';
import { historySummary, historyReads } from '../../history';
import { builtInSnippetKey } from '../../strategy';
import { reviewStatusVariant } from '../../review';
import {
    answeredNetAfter,
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
    quoteDiscountPercent,
    roundChange,
    terminalExplanation,
    terminalLabel,
    triggerLabel,
    writeLabels,
} from '../../decision';

const { Criteria } = Shopware.Data;

Shopware.Component.register('merchant-quote-agent-detail', {
    template,

    inject: ['repositoryFactory', 'acl'],

    data() {
        return {
            record: null,
            rounds: [],
            quote: null,
            isLoading: false,
            feedbackFor: null as { id: string; feedbackReasons?: string[]; feedbackComment?: string } | null,
            feedbackPrompt: '',
            // Keyed by strategyVersionId. Resolved once per load(), not per
            // pass: several rounds of the same quote can share a version, and
            // a version can outlive the strategy row that named it.
            strategyByVersion: {},
        };
    },

    computed: {
        decisionRepository() {
            return this.repositoryFactory.create('merchant_quote_agent_decision');
        },

        quoteRepository() {
            return this.repositoryFactory.create('quote');
        },

        strategyRepository() {
            return this.repositoryFactory.create('merchant_quote_agent_strategy');
        },

        strategyVersionRepository() {
            return this.repositoryFactory.create('merchant_quote_agent_strategy_version');
        },

        /** The rounds this page renders, record itself included as a fallback of one. */
        recordRounds() {
            return this.rounds.length > 0 ? this.rounds : (this.record ? [this.record] : []);
        },

        pendingDraftId() {
            const last = this.recordRounds[this.recordRounds.length - 1] as { id: string; reviewStatus?: string | null } | undefined;

            return last?.reviewStatus === 'pending' ? last.id : null;
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
            // The quote as the agent first found it: the earliest pass's own
            // opening total, which is the snapshot QuoteBaseline stamps and so
            // the total every reply's percentage is quoted against. Read off
            // the rounds rather than fetched, because they are already here
            // and already sorted createdAt ASC.
            const baselineNet = this.recordRounds.find(
                (round) => Number.isFinite(round.totalNetBefore),
            )?.totalNetBefore ?? null;

            return this.recordRounds.map((round, index) => this.formatRun(round, index, baselineNet));
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

            return last ? last.reviewStatusLabel ?? last.outcomeLabel : '–';
        },

        statusVariant() {
            if (this.terminalState !== null) {
                return this.orderPlaced ? 'positive' : 'neutral';
            }

            const last = this.runs[this.runs.length - 1];

            return last?.reviewStatusLabel ? last.reviewStatusVariant : last?.outcomeVariant ?? 'neutral';
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
        answeredNetAfter,
        formatCurrency,
        formatDate,
        formatPercent,

        openFeedback(round: { id: string; feedbackReasons?: string[]; feedbackComment?: string }, prompt = '') {
            this.feedbackFor = round;
            this.feedbackPrompt = prompt;
        },

        onReviewed({ edited }: { edited: boolean }) {
            const round = this.recordRounds.find((r) => (r as { id: string }).id === this.pendingDraftId) as { id: string } | undefined;
            void this.load();

            if (edited && round) {
                this.openFeedback(round, this.$tc('merchant-quote-agent.feedback.offerAfterEdit'));
            }
        },

        onRejected() {
            const round = this.recordRounds.find((r) => (r as { id: string }).id === this.pendingDraftId) as { id: string } | undefined;
            void this.load();

            if (round) {
                this.openFeedback(round);
            }
        },

        onFeedbackSaved() {
            this.feedbackFor = null;
            void this.load();
        },

        /** The outcome entry that closes the stream: the state, then why. */
        terminalTitle(state) {
            return terminalLabel(this, state);
        },

        terminalWhy(state) {
            return terminalExplanation(this, state);
        },

        async load() {
            const requested = this.$route.params.id;

            this.isLoading = true;

            try {
                this.record = await this.decisionRepository.get(requested, Shopware.Context.api);

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

                // Never allowed to throw: see loadStrategies(). A decision's
                // own data must still render even when this fails.
                await this.loadStrategies(requested);
            } catch (error) {
                this.record = null;
                this.rounds = [];
                this.quote = null;
                this.strategyByVersion = {};
                // eslint-disable-next-line no-console
                console.error('merchant-quote-agent: failed to load decision flow', error);
            } finally {
                this.isLoading = false;
            }
        },

        /**
         * Resolves every pass's `strategyVersionId` to a version and its
         * strategy: two straight reads each, no association -- the entities
         * deliberately declare none. `requested` is the route id captured
         * before the round trip; a response for a decision the page has since
         * moved on from is dropped, the same shape as loadConfig() in the
         * access page (page/merchant-quote-agent-access/index.ts).
         *
         * Never throws. A version or strategy that cannot be read resolves to
         * "unavailable" per id (see loadStrategyForVersion) rather than
         * bubbling -- this is an audit trail, and a lookup failing here must
         * not take the rest of an already-loaded decision down with it.
         */
        async loadStrategies(requested) {
            const versionIds = [...new Set(
                this.recordRounds
                    .map((round) => round.strategyVersionId)
                    .filter((id) => typeof id === 'string' && id.length > 0),
            )];

            if (versionIds.length === 0) {
                if (this.$route.params.id === requested) {
                    this.strategyByVersion = {};
                }

                return;
            }

            const entries = await Promise.all(
                versionIds.map(async (versionId) => [versionId, await this.loadStrategyForVersion(versionId)]),
            );

            if (this.$route.params.id !== requested) {
                return;
            }

            this.strategyByVersion = Object.fromEntries(entries);
        },

        /**
         * One version, then its strategy by `strategyId` -- no association to
         * follow. Any failure (the version gone, its strategy gone, a
         * transient error) reports as unavailable rather than throwing: a
         * decision can reference a version whose strategy was archived and
         * pruned, or a row restored from a partial backup, and that is a
         * valid thing for an audit record to show, not an error.
         */
        async loadStrategyForVersion(versionId) {
            try {
                const version = await this.strategyVersionRepository.get(versionId, Shopware.Context.api);

                if (!version) {
                    return { unavailable: true };
                }

                const strategy = await this.strategyRepository.get(version.strategyId, Shopware.Context.api);

                if (!strategy) {
                    return { unavailable: true };
                }

                const key = builtInSnippetKey(strategy.id);

                return {
                    unavailable: false,
                    version: version.version,
                    prompt: version.prompt,
                    name: key === null ? strategy.name : this.$tc(`merchant-quote-agent.strategy.builtIn.${key}.name`),
                };
            } catch (error) {
                return { unavailable: true };
            }
        },

        /** The resolved strategy for one pass, or null when it ran with none configured. */
        strategyFor(round) {
            if (typeof round.strategyVersionId !== 'string' || round.strategyVersionId.length === 0) {
                return null;
            }

            return this.strategyByVersion[round.strategyVersionId] ?? null;
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
         */
        formatRun(round, index, baselineNet = null) {
            const answered = answeredTheBuyer(round.outcome, round.reviewStatus ?? null);

            return {
                id: round.id,
                index: index + 1,
                raw: round,
                answered,
                // A state-change pass with nothing to answer carries no ask, no
                // band and no model. It gets one line instead of the same card
                // as a round that negotiated.
                isNoop: !answered && (round.outcome === 'nothing_to_do' || round.outcome === 'handed_over'),
                title: this.$tc('merchant-quote-agent.detail.agentTitle'),
                timestamp: formatDate(round.createdAt),
                outcomeLabel: outcomeLabel(this, round.outcome),
                outcomeVariant: round.reviewStatus ? reviewStatusVariant(round.reviewStatus) : outcomeVariant(round.outcome),
                asks: askItems(this, round.interpretedAsks),
                band: round.band,
                bandVariant: bandVariant(round.band),
                cap: formatPercent(round.maxDiscountPercent),
                // The quote's discount, not this pass's. A pass that holds the
                // previous round's offer records a 0 reduction of its own, and
                // showing that here read as "no discount" next to a reduced
                // total and a reply quoting 15% — live quote 1012.
                granted: answered ? formatPercent(quoteDiscountPercent(baselineNet, answeredNetAfter(round))) : null,
                totals: answered ? roundChange(this, round) : null,
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
                // The buyer's own words come off the QUOTE, which is the
                // better source whenever it can be read; `buyerAsk` on the
                // record is the fallback when it cannot. See recordedAsks().
                reply: round.replyToBuyer || null,
                replyLabel: round.reviewStatus
                    ? this.$tc('merchant-quote-agent.review.draftReplyLabel')
                    : this.$tc('merchant-quote-agent.detail.replyLabel'),
                reviewStatus: round.reviewStatus ?? null,
                reviewStatusLabel: round.reviewStatus ? this.$tc(`merchant-quote-agent.review.status.${round.reviewStatus}`) : null,
                reviewStatusVariant: reviewStatusVariant(round.reviewStatus ?? null),
                sentReply: round.sentReply && round.sentReply !== round.replyToBuyer ? round.sentReply : null,
                feedbackReasons: (round.feedbackReasons ?? []).map((r) => this.$tc(`merchant-quote-agent.feedback.reasons.${r}`)),
                feedbackComment: round.feedbackComment || null,
                technical: this.technical(round),
                // null when the pass ran with no strategy configured -- a
                // valid state, not an error, and rendered as nothing at all.
                strategy: this.strategyFor(round),
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
