import template from './merchant-quote-agent-detail.html.twig';

const { Criteria } = Shopware.Data;

Shopware.Component.register('merchant-quote-agent-detail', {
    template,

    inject: ['repositoryFactory'],

    data() {
        return {
            record: null,
            rounds: [],
            isLoading: false,
            activeTab: 'flow',
        };
    },

    computed: {
        decisionRepository() {
            return this.repositoryFactory.create('merchant_quote_agent_decision');
        },

        quoteNumber() {
            return this.record?.quoteNumber ?? '–';
        },

        totalRounds() {
            return this.rounds?.length ?? (this.record ? 1 : 0);
        },

        latestRound() {
            if (this.rounds && this.rounds.length > 0) {
                return this.rounds[this.rounds.length - 1];
            }
            return this.record;
        },

        finalTerminalState() {
            return this.latestRound?.terminalState ?? this.record?.terminalState ?? null;
        },

        finalTerminalAt() {
            return this.latestRound?.terminalAt ?? this.record?.terminalAt ?? null;
        },

        finalOutcome() {
            return this.latestRound?.outcome ?? this.record?.outcome ?? null;
        },

        formattedRounds() {
            const list = this.rounds && this.rounds.length > 0 ? this.rounds : (this.record ? [this.record] : []);
            const total = list.length;

            return list.map((round, idx) => this.formatRound(round, idx, total));
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

                if (this.record?.quoteNumber) {
                    const criteria = new Criteria(1, 50);
                    criteria.addFilter(Criteria.equals('quoteNumber', this.record.quoteNumber));
                    criteria.addSorting(Criteria.sort('createdAt', 'ASC'));
                    const result = await this.decisionRepository.search(criteria, Shopware.Context.api);
                    this.rounds = Array.from(result);
                } else if (this.record) {
                    this.rounds = [this.record];
                }
            } catch (error) {
                // eslint-disable-next-line no-console
                console.error('merchant-quote-agent: failed to load decision flow', error);
            } finally {
                this.isLoading = false;
            }
        },

        formatRound(round, index, total) {
            const isInitial = index === 0;
            const roundNumber = index + 1;

            return {
                id: round.id,
                roundNumber,
                isInitial,
                badgeLabel: this.$t
                    ? this.$t('merchant-quote-agent.detail.roundBadge', { index: roundNumber, total })
                    : `${roundNumber} / ${total}`,
                title: isInitial
                    ? this.$tc('merchant-quote-agent.detail.initialRequest')
                    : this.$tc('merchant-quote-agent.detail.counterRequest'),
                timestamp: this.formatDate(round.createdAt),
                buyerComment: round.buyerComment || null,
                interpretedAsks: this.extractAsks(round.interpretedAsks),
                band: round.band || '–',
                maxDiscountPercent: round.maxDiscountPercent !== null ? `${round.maxDiscountPercent}%` : '–',
                model: round.model || '–',
                modelLatencyMs: round.modelLatencyMs !== null ? `${round.modelLatencyMs} ms` : null,
                tokens: round.promptTokens && round.completionTokens
                    ? `${round.promptTokens} / ${round.completionTokens}`
                    : null,
                outcome: round.outcome,
                outcomeVariant: this.outcomeVariant(round.outcome),
                outcomeLabel: this.outcomeLabel(round.outcome),
                escalationReason: round.escalationReason || null,
                discountPercentGranted: round.discountPercentGranted !== null ? `${round.discountPercentGranted}%` : null,
                totalNetBefore: round.totalNetBefore !== null ? this.formatCurrency(round.totalNetBefore, round.currencyIso) : null,
                totalNetAfter: round.totalNetAfter !== null ? this.formatCurrency(round.totalNetAfter, round.currencyIso) : null,
                messageToBuyer: this.extractMessage(round.rawProposal),
                rawRecord: round,
            };
        },

        extractAsks(asks) {
            if (!asks || typeof asks !== 'object') {
                return [];
            }

            const items = [];
            if (asks.targetDiscountPercent !== undefined && asks.targetDiscountPercent !== null) {
                items.push({
                    label: this.$tc('merchant-quote-agent.detail.discountAsked'),
                    value: `${asks.targetDiscountPercent}%`,
                });
            }

            if (asks.lines && Array.isArray(asks.lines)) {
                asks.lines.forEach((line, idx) => {
                    if (line.targetUnitPriceNet !== undefined && line.targetUnitPriceNet !== null) {
                        items.push({
                            label: `${this.$tc('merchant-quote-agent.detail.targetPrice')} #${idx + 1}`,
                            value: `${Number(line.targetUnitPriceNet).toFixed(2)}`,
                        });
                    }
                    if (line.quantity !== undefined && line.quantity !== null) {
                        items.push({
                            label: `${this.$tc('merchant-quote-agent.detail.quantity')} #${idx + 1}`,
                            value: `${line.quantity}`,
                        });
                    }
                });
            }

            return items;
        },

        extractMessage(rawProposal) {
            if (!rawProposal) {
                return null;
            }

            try {
                const parsed = typeof rawProposal === 'string' ? JSON.parse(rawProposal) : rawProposal;
                return parsed.message || parsed.comment || null;
            } catch {
                return null;
            }
        },

        outcomeVariant(outcome) {
            if (outcome === 'replied') {
                return 'success';
            }
            if (outcome === 'escalated') {
                return 'critical';
            }
            if (outcome === 'declined') {
                return 'neutral';
            }
            return 'info';
        },

        outcomeLabel(outcome) {
            const key = `merchant-quote-agent.list.outcome.${outcome}`;
            const label = this.$tc(key);

            return label === key ? outcome : label;
        },

        bandVariant(band) {
            if (band === 'grant') {
                return 'success';
            }
            if (band === 'counter') {
                return 'info';
            }
            if (band === 'escalate') {
                return 'critical';
            }
            return 'neutral';
        },

        formatCurrency(value, currencyIso = 'EUR') {
            if (value === null || value === undefined) {
                return '–';
            }

            return `${Number(value).toFixed(2)} ${currencyIso}`;
        },

        terminalLabel(state) {
            const key = `merchant-quote-agent.detail.terminal.${state}`;
            const label = this.$tc(key);

            return label === key ? state : label;
        },

        formatDate(value) {
            const dateFilter = Shopware.Filter?.getByName?.('date');

            return dateFilter ? dateFilter(value) : String(value);
        },

        dash(value) {
            return value === null || value === undefined || value === '' ? '–' : value;
        },
    },
});
