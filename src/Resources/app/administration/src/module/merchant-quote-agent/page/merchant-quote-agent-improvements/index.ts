import template from './merchant-quote-agent-improvements.html.twig';
import { formatDate, formatDateShort, formatPercent } from '../../decision';
import {
    escalationDelta,
    isBetter,
    nextRunDueAt,
    runsEmptyState,
    unshowableReason,
} from '../../improvement.ts';

const { Criteria } = Shopware.Data;

/** How many decisions the nightly run replayed and skipped, and what it found. */
const RUN_LIMIT = 50;
const PROPOSAL_LIMIT = 20;

/**
 * Settings -> Nightly self-improvement. What the last runs found, and the
 * proposals waiting for a human to accept or reject them.
 *
 * Reads `improvementEnabled` / `improvementCadence` at the GLOBAL config
 * scope only, like this module's list page reads the escalation SLA
 * (`loadSla()`) -- both are per-sales-channel overridable settings, and both
 * pages show one figure for the shop rather than adding a channel selector
 * neither the brief nor the sibling pages have. A shop that overrides the
 * cadence per channel gets an approximate "next run due" from this page; the
 * Runs table itself is exact, since every row already carries its own real
 * `salesChannelId`.
 *
 * The trade-off this scope choice makes: a shop that enabled the feature on
 * only ONE channel (never the global scope) reads `enabled = false` here --
 * a channel override does NOT inherit upward to the global value this page
 * reads. `runsEmptyState()` covers for that: it only ever returns `disabled`
 * when `runs` is also empty, and a run row is proof the feature is on
 * somewhere, so an enabled-per-channel shop with any run history still sees
 * that history rather than a wrong "turned off" banner.
 *
 * Accepting or rejecting never needs a confirmation modal the way archiving a
 * strategy does: this page IS the confirmation -- a human reads the findings
 * and the A/B numbers before clicking a button labelled exactly what it does.
 */
Shopware.Component.register('merchant-quote-agent-improvements', {
    template,

    inject: ['repositoryFactory', 'syncService', 'acl'],

    data() {
        return {
            enabled: false,
            cadence: 'daily',
            runs: [],
            proposals: [],
            activeVersions: {},
            strategyNames: {},
            salesChannelNames: {},
            isLoading: false,
            actingOn: null,
            // Frozen once per load rather than read live, so "not due until
            // 14:32" does not creep forward while the page sits open.
            now: new Date(),
            error: null,
        };
    },

    computed: {
        canEdit() {
            return this.acl.can('merchant_quote_agent.editor');
        },

        runRepository() {
            return this.repositoryFactory.create('merchant_quote_agent_improvement_run');
        },

        versionRepository() {
            return this.repositoryFactory.create('merchant_quote_agent_strategy_version');
        },

        strategyRepository() {
            return this.repositoryFactory.create('merchant_quote_agent_strategy');
        },

        httpClient() {
            return this.syncService.httpClient;
        },

        newestRun() {
            return this.runs[0] ?? null;
        },

        /** The newest run's findings -- only a completed run ever writes any. */
        findings() {
            return this.newestRun?.findings ?? [];
        },

        emptyState() {
            return runsEmptyState(this.enabled, this.runs, this.cadence, this.now);
        },

        newestCompletedRun() {
            return this.runs.find((run) => run.status === 'completed') ?? null;
        },

        nextDueAt() {
            return nextRunDueAt(this.newestCompletedRun?.finishedAt ?? null, this.cadence);
        },

        columns() {
            return [
                { property: 'startedAt', label: this.$tc('merchant-quote-agent.improvement.columnDate') },
                { property: 'salesChannelId', label: this.$tc('merchant-quote-agent.improvement.columnSalesChannel') },
                { property: 'window', label: this.$tc('merchant-quote-agent.improvement.columnWindow') },
                { property: 'sampled', label: this.$tc('merchant-quote-agent.improvement.columnSampled') },
                { property: 'findings', label: this.$tc('merchant-quote-agent.improvement.columnFindings') },
                { property: 'tokens', label: this.$tc('merchant-quote-agent.improvement.columnTokens') },
                { property: 'status', label: this.$tc('merchant-quote-agent.improvement.columnStatus') },
            ];
        },
    },

    created() {
        this.load();
    },

    methods: {
        formatDate,
        formatDateShort,
        formatPercent,
        escalationDelta,
        isBetter,

        statusVariant(status) {
            return {
                running: 'neutral',
                completed: 'positive',
                failed: 'critical',
                no_data: 'attention',
            }[status] ?? 'neutral';
        },

        statusLabel(status) {
            return this.$tc(`merchant-quote-agent.improvement.runStatus.${status}`);
        },

        /** Why a "better" badge is hidden, for the sentence shown in its place. */
        unshowableReasonLabel(evaluation) {
            const reason = unshowableReason(evaluation);

            return reason === null ? null : this.$tc(`merchant-quote-agent.improvement.unshowable.${reason}`);
        },

        abColumns() {
            return [
                { property: 'arm', label: this.$tc('merchant-quote-agent.improvement.abArm') },
                { property: 'sampled', label: this.$tc('merchant-quote-agent.improvement.abSampled') },
                { property: 'escalationRate', label: this.$tc('merchant-quote-agent.improvement.abEscalationRate') },
                { property: 'meanGrantedPercent', label: this.$tc('merchant-quote-agent.improvement.abMeanGranted') },
                { property: 'modelRefusals', label: this.$tc('merchant-quote-agent.improvement.abModelRefusals') },
                { property: 'failures', label: this.$tc('merchant-quote-agent.improvement.abFailures') },
                { property: 'unmeasured', label: this.$tc('merchant-quote-agent.improvement.abUnmeasured') },
            ];
        },

        /** One row for each arm, labelled, so the grid needs no special-casing per row. */
        abRows(evaluation) {
            return [
                { arm: this.$tc('merchant-quote-agent.improvement.abControl'), ...evaluation.control },
                { arm: this.$tc('merchant-quote-agent.improvement.abCandidate'), ...evaluation.candidate },
            ];
        },

        windowLabel(run) {
            return `${this.formatDateShort(run.windowFrom)} – ${this.formatDateShort(run.windowTo)}`;
        },

        tokensLabel(run) {
            if (run.promptTokens === null && run.completionTokens === null) {
                return '–';
            }

            return `${run.promptTokens ?? 0} + ${run.completionTokens ?? 0}`;
        },

        salesChannelLabel(salesChannelId) {
            return this.salesChannelNames[salesChannelId] ?? salesChannelId;
        },

        strategyNameFor(strategyId) {
            return this.strategyNames[strategyId] ?? null;
        },

        activePromptFor(strategyId) {
            return this.activeVersions[strategyId]?.prompt ?? null;
        },

        activeVersionNumberFor(strategyId) {
            return this.activeVersions[strategyId]?.version ?? null;
        },

        async load() {
            this.error = null;
            this.isLoading = true;
            this.now = new Date();

            try {
                await Promise.all([this.loadConfig(), this.loadRuns(), this.loadProposals()]);
            } finally {
                this.isLoading = false;
            }
        },

        /**
         * `getValues('MerchantQuoteAgentPlugin.config')` with no sales-channel
         * id reads the GLOBAL config, not a merge with any channel override --
         * Shopware's own config semantics run the other way (a channel falls
         * back to the global value, never the reverse). So a shop that only
         * enabled the feature on ONE channel reads `enabled = false` here;
         * see this component's own docblock for why `runsEmptyState()` is
         * what keeps that from showing a wrong "turned off" banner over real
         * run history.
         */
        async loadConfig() {
            try {
                const values = await Shopware.Service('systemConfigApiService')
                    .getValues('MerchantQuoteAgentPlugin.config');

                this.enabled = values?.['MerchantQuoteAgentPlugin.config.improvementEnabled'] === true;
                this.cadence = values?.['MerchantQuoteAgentPlugin.config.improvementCadence'] ?? 'daily';
            } catch (error) {
                // Read-only figure: a merchant without system_config:read sees
                // the feature as off rather than the page breaking outright.
                this.enabled = false;
                // eslint-disable-next-line no-console
                console.error('merchant-quote-agent: improvement config unavailable', error);
            }
        },

        async loadRuns() {
            try {
                const criteria = new Criteria(1, RUN_LIMIT);
                criteria.addSorting(Criteria.sort('startedAt', 'DESC'));

                const result = await this.runRepository.search(criteria, Shopware.Context.api);

                this.runs = Array.from(result);
                await this.loadSalesChannelNames(this.runs.map((run) => run.salesChannelId).filter(Boolean));
            } catch (error) {
                this.runs = [];
                this.error = this.messageFor(error);
            }
        },

        async loadSalesChannelNames(ids) {
            const unique = [...new Set(ids)];

            if (unique.length === 0) {
                return;
            }

            try {
                const salesChannelRepository = this.repositoryFactory.create('sales_channel');
                const criteria = new Criteria(1, unique.length);
                criteria.setIds(unique);

                const result = await salesChannelRepository.search(criteria, Shopware.Context.api);

                this.salesChannelNames = Object.fromEntries(
                    Array.from(result).map((channel) => [channel.id, channel.name]),
                );
            } catch (error) {
                // Names stay absent; the table falls back to the raw id.
                // eslint-disable-next-line no-console
                console.error('merchant-quote-agent: sales channel names unavailable', error);
            }
        },

        async loadProposals() {
            try {
                const criteria = new Criteria(1, PROPOSAL_LIMIT);
                criteria.addFilter(Criteria.equals('status', 'proposed'));
                criteria.addSorting(Criteria.sort('createdAt', 'DESC'));

                const result = await this.versionRepository.search(criteria, Shopware.Context.api);

                this.proposals = Array.from(result);
                await this.loadLineageContext(this.proposals.map((proposal) => proposal.strategyId));
            } catch (error) {
                this.proposals = [];
                this.error = this.messageFor(error);
            }
        },

        /** The active version and the name of each proposal's lineage, for the diff and the heading. */
        async loadLineageContext(strategyIds) {
            const unique = [...new Set(strategyIds)];

            if (unique.length === 0) {
                this.activeVersions = {};
                this.strategyNames = {};

                return;
            }

            try {
                const versionCriteria = new Criteria(1, unique.length);
                versionCriteria.addFilter(Criteria.equalsAny('strategyId', unique));
                versionCriteria.addFilter(Criteria.equals('status', 'active'));

                const strategyCriteria = new Criteria(1, unique.length);
                strategyCriteria.setIds(unique);

                const [versions, strategies] = await Promise.all([
                    this.versionRepository.search(versionCriteria, Shopware.Context.api),
                    this.strategyRepository.search(strategyCriteria, Shopware.Context.api),
                ]);

                // One active row per strategy by contract (StrategyWriteGuard),
                // but reduced to the newest by version rather than trusted to
                // be singular, so a data inconsistency shows the current
                // prompt rather than throwing.
                this.activeVersions = {};

                for (const version of versions) {
                    const current = this.activeVersions[version.strategyId];

                    if (!current || (version.version ?? 0) > (current.version ?? 0)) {
                        this.activeVersions[version.strategyId] = version;
                    }
                }

                this.strategyNames = Object.fromEntries(
                    Array.from(strategies).map((strategy) => [strategy.id, strategy.name]),
                );
            } catch (error) {
                // The proposals themselves still loaded; only the diff/name context is missing.
                // eslint-disable-next-line no-console
                console.error('merchant-quote-agent: proposal lineage context unavailable', error);
            }
        },

        async accept(versionId) {
            await this.decide(versionId, 'accept');
        },

        async reject(versionId) {
            await this.decide(versionId, 'reject');
        },

        async decide(versionId, action) {
            this.error = null;
            this.actingOn = versionId;

            try {
                await this.httpClient.post(
                    `_action/merchant-quote-agent/proposal/${versionId}/${action}`,
                    {},
                    { headers: this.syncService.getBasicHeaders() },
                );

                await this.loadProposals();
            } catch (error) {
                this.error = error?.response?.status === 409
                    ? this.$tc('merchant-quote-agent.improvement.alreadyDecided')
                    : this.messageFor(error);

                // Either way the proposed list may now be stale (someone else
                // decided it, or it no longer exists) -- reload so the buttons
                // shown match what is actually still pending.
                await this.loadProposals();
            } finally {
                this.actingOn = null;
            }
        },

        messageFor(error) {
            const response = error?.response;
            const detail = response?.data?.errors?.[0]?.detail;

            return detail ?? error?.message ?? this.$tc('merchant-quote-agent.improvement.requestFailed');
        },
    },
});
