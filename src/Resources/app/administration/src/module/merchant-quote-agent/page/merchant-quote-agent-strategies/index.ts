import template from './merchant-quote-agent-strategies.html.twig';
import { isBuiltIn, sortStrategies, builtInSnippetKey } from '../../strategy.ts';

/**
 * Settings -> Negotiation strategies. The library's CRUD.
 *
 * It lives on its own page rather than inside the plugin configuration card
 * because the card is saved by the configuration page's Save button and entity
 * writes are not. Two save models in one card is the confusion worth avoiding.
 *
 * Editing NEVER rewrites a version: save() inserts version N+1, so every past
 * decision keeps resolving the prompt it actually used. StrategyWriteGuard
 * refuses an update server-side, so a mistake here surfaces as an error rather
 * than as silent history loss.
 *
 * Deleting is archiving, for the same reason.
 */
Shopware.Component.register('merchant-quote-agent-strategies', {
    template,

    inject: ['repositoryFactory', 'acl'],

    data() {
        return {
            strategies: [],
            selected: null,
            prompt: '',
            currentVersion: null,
            isLoading: false,
            isSaving: false,
            nameModalOpen: false,
            nameDraft: '',
            // 'create' opens a blank strategy, 'duplicate' copies the selected
            // built-in's prompt, 'rename' renames the selected custom one.
            nameModalIntent: 'create',
            pendingArchive: null,
            error: null,
        };
    },

    computed: {
        canEdit() {
            return this.acl.can('merchant_quote_agent.editor');
        },

        repository() {
            return this.repositoryFactory.create('merchant_quote_agent_strategy');
        },

        versionRepository() {
            return this.repositoryFactory.create('merchant_quote_agent_strategy_version');
        },

        selectedIsBuiltIn() {
            return this.selected !== null && isBuiltIn(this.selected.id);
        },

        columns() {
            return [
                { property: 'name', label: this.$tc('merchant-quote-agent.strategy.columnName') },
                { property: 'type', label: this.$tc('merchant-quote-agent.strategy.columnType') },
                { property: 'description', label: this.$tc('merchant-quote-agent.strategy.columnDescription') },
            ];
        },

        /** One label per intent, used for both the modal's title and its confirm button. */
        nameModalLabel() {
            if (this.nameModalIntent === 'rename') {
                return this.$tc('merchant-quote-agent.strategy.rename');
            }

            if (this.nameModalIntent === 'duplicate') {
                return this.$tc('merchant-quote-agent.strategy.duplicate');
            }

            return this.$tc('merchant-quote-agent.strategy.add');
        },
    },

    created() {
        this.load();
    },

    methods: {
        rowIsBuiltIn(strategy) {
            return isBuiltIn(strategy.id);
        },

        displayName(strategy) {
            const key = builtInSnippetKey(strategy.id);

            return key === null ? strategy.name : this.$tc(`merchant-quote-agent.strategy.builtIn.${key}.name`);
        },

        displayDescription(strategy) {
            const key = builtInSnippetKey(strategy.id);

            return key === null
                ? (strategy.description ?? '')
                : this.$tc(`merchant-quote-agent.strategy.builtIn.${key}.description`);
        },

        async load() {
            this.error = null;
            this.isLoading = true;

            try {
                const criteria = new Shopware.Data.Criteria(1, 100);
                criteria.addFilter(Shopware.Data.Criteria.equals('archivedAt', null));

                const result = await this.repository.search(criteria, Shopware.Context.api);

                this.strategies = sortStrategies([...result]);
            } catch (error) {
                this.error = this.messageFor(error);
            } finally {
                this.isLoading = false;
            }
        },

        /**
         * Two clicks in quick succession start two requests that can resolve
         * out of order. `requested` is captured before the await and checked
         * after it, so a response for a strategy the admin has since clicked
         * away from cannot overwrite `prompt`/`currentVersion` for whatever is
         * selected by the time it arrives -- otherwise a following save()
         * would append the wrong strategy's text as a new, immutable version.
         */
        async select(strategy) {
            const requested = strategy.id;

            this.error = null;
            this.selected = strategy;
            this.prompt = '';
            this.currentVersion = null;

            try {
                const version = await this.newestVersion(requested);

                if (this.selected?.id !== requested) {
                    return;
                }

                this.prompt = version?.prompt ?? '';
                this.currentVersion = version?.version ?? null;
            } catch (error) {
                if (this.selected?.id === requested) {
                    this.error = this.messageFor(error);
                }
            }
        },

        async newestVersion(strategyId) {
            const criteria = new Shopware.Data.Criteria(1, 1);
            criteria.addFilter(Shopware.Data.Criteria.equals('strategyId', strategyId));
            criteria.addSorting(Shopware.Data.Criteria.sort('version', 'DESC'));

            const result = await this.versionRepository.search(criteria, Shopware.Context.api);

            return result.first() ?? null;
        },

        openNameModal(intent) {
            this.nameModalIntent = intent;
            this.nameDraft = intent === 'rename' ? (this.selected?.name ?? '') : '';
            this.nameModalOpen = true;
        },

        async startRename(strategy) {
            await this.select(strategy);
            this.openNameModal('rename');
        },

        /**
         * Load the built-in's prompt BEFORE offering to name the copy. The
         * modal's confirm reads `this.prompt`, so opening it alongside an
         * unawaited select() would race and copy whatever was in the editor
         * before -- an empty string on first use.
         */
        async duplicate(strategy) {
            await this.select(strategy);
            this.openNameModal('duplicate');
        },

        async confirmName() {
            if (this.nameDraft.trim() === '') {
                return;
            }

            this.error = null;
            this.isSaving = true;

            try {
                if (this.nameModalIntent === 'rename') {
                    await this.rename(this.nameDraft.trim());
                } else {
                    // 'duplicate' seeds version 1 from whatever is currently
                    // loaded in the editor; 'create' starts blank.
                    await this.create(
                        this.nameDraft.trim(),
                        this.nameModalIntent === 'duplicate' ? this.prompt : '',
                    );
                }

                this.nameModalOpen = false;
            } catch (error) {
                this.error = this.messageFor(error);
            } finally {
                this.isSaving = false;
            }
        },

        async create(name, prompt) {
            const strategy = this.repository.create(Shopware.Context.api);
            strategy.name = name;

            await this.repository.save(strategy, Shopware.Context.api);
            await this.appendVersion(strategy.id, 1, prompt);
            await this.load();

            const created = this.strategies.find((candidate) => candidate.id === strategy.id);

            if (created !== undefined) {
                await this.select(created);
            }
        },

        async rename(name) {
            const strategy = await this.repository.get(this.selected.id, Shopware.Context.api);
            strategy.name = name;

            await this.repository.save(strategy, Shopware.Context.api);
            await this.load();
        },

        /** Appends. Never updates -- StrategyWriteGuard refuses an update anyway. */
        async appendVersion(strategyId, version, prompt) {
            const row = this.versionRepository.create(Shopware.Context.api);
            row.strategyId = strategyId;
            row.version = version;
            row.prompt = prompt;

            await this.versionRepository.save(row, Shopware.Context.api);
        },

        async save() {
            this.error = null;

            if (this.selected === null || this.selectedIsBuiltIn) {
                return;
            }

            this.isSaving = true;

            try {
                const newest = await this.newestVersion(this.selected.id);

                // Re-read rather than trusting this.currentVersion: another
                // admin may have saved since this page loaded, and the unique
                // key on (strategy_id, version) would reject the collision.
                await this.appendVersion(this.selected.id, (newest?.version ?? 0) + 1, this.prompt);
                await this.select(this.selected);
            } catch (error) {
                this.error = this.messageFor(error);
            } finally {
                this.isSaving = false;
            }
        },

        confirmArchive(strategy) {
            this.pendingArchive = strategy;
        },

        async archive(strategy) {
            this.error = null;
            this.pendingArchive = null;
            this.isSaving = true;

            try {
                const row = await this.repository.get(strategy.id, Shopware.Context.api);
                row.archivedAt = new Date().toISOString();

                await this.repository.save(row, Shopware.Context.api);

                if (this.selected?.id === strategy.id) {
                    this.selected = null;
                    this.prompt = '';
                    this.currentVersion = null;
                }

                await this.load();
            } catch (error) {
                this.error = this.messageFor(error);
            } finally {
                this.isSaving = false;
            }
        },

        messageFor(error) {
            const response = error?.response;
            const detail = response?.data?.errors?.[0]?.detail;

            return detail ?? error?.message ?? this.$tc('merchant-quote-agent.strategy.requestFailed');
        },
    },
});
