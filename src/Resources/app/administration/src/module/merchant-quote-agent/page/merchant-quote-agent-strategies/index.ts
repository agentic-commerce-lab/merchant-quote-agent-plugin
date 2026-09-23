import template from './merchant-quote-agent-strategies.html.twig';
import {
    isBuiltIn,
    sortStrategies,
    builtInSnippetKey,
    newStrategySync,
    versionedIds,
    VERSIONED_AGGREGATION,
    type StrategyLike,
} from '../../strategy.ts';
import { isDuplicatePin, isSavable } from '../../assignment.ts';

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
 *
 * Creating writes the strategy and its version 1 in one sync request, so a
 * strategy never exists without a prompt to resolve. A row that predates this
 * still lists here, badged, so it can be given a prompt or archived -- but no
 * strategy select offers it (selectableStrategies).
 */
Shopware.Component.register('merchant-quote-agent-strategies', {
    template,

    inject: ['repositoryFactory', 'syncService', 'acl'],

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
            // The first prompt of a created or duplicated strategy.
            promptDraft: '',
            versionedIds: new Set<string>(),
            // 'create' asks for a first prompt, 'duplicate' seeds it from the
            // selected built-in's, 'rename' renames the selected custom one.
            nameModalIntent: 'create',
            pendingArchive: null,
            error: null,
            // Which tab is showing, kept in sync by the template's
            // @new-item-active handler on <sw-tabs> (that component owns its
            // own active-tab state internally via default-item; this mirrors
            // it for anything that needs to read which tab is current).
            // 'library' was the whole page before the assignment ladder
            // existed, so it stays the default.
            activeTab: 'library',
            assignments: [],
            // ruleId -> { name, priority }, populated by loadAssignments(). The
            // assignment entity stores ruleId as a plain UUID with no DAL
            // association, so the rule's own priority (what orders rung 2)
            // needs a second read; the select bound to ruleId shows the name
            // itself, by fetching the rule the same way -- see its own
            // repository.get() -- so this map exists only for what that select
            // does not surface: the priority column and the sort order below it.
            ruleNames: {},
            // Its own field rather than reusing `error`: the library tab's
            // load() and this tab's loadAssignments() both run un-awaited from
            // created(), so sharing one field let a failure surface under the
            // wrong tab, or get overwritten before anyone saw it, because the
            // Assignments tab had no banner of its own to show it in.
            assignmentError: null,
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

        assignmentRepository() {
            return this.repositoryFactory.create('merchant_quote_agent_strategy_assignment');
        },

        /** Only reached to name the customer in a duplicate-pin refusal -- see saveAssignment(). */
        customerRepository() {
            return this.repositoryFactory.create('customer');
        },

        /** Only reached in loadAssignments(), to back-fill ruleNames for rung 2's rows. */
        ruleRepository() {
            return this.repositoryFactory.create('rule');
        },

        /** Rung 1 of the ladder. Rules and split arms are Tasks 4-5's own filters over the same array. */
        pins() {
            return this.assignments.filter((row) => row.kind === 'pin');
        },

        pinColumns() {
            return [
                { property: 'customerId', label: this.$tc('merchant-quote-agent.assignment.columnCustomer') },
                { property: 'strategyId', label: this.$tc('merchant-quote-agent.assignment.columnStrategy') },
                { property: 'salesChannelId', label: this.$tc('merchant-quote-agent.assignment.columnSalesChannel') },
            ];
        },

        /**
         * Rung 2 of the ladder, ordered the way the resolver walks it: highest
         * `rule.priority` first, so the grid reads in evaluation order and a
         * merchant can reason about two rules that could both match the same
         * quote. A row whose rule metadata has not loaded yet (freshly added,
         * not yet picked) sorts last rather than jumping around the list.
         */
        rules() {
            return this.assignments
                .filter((row) => row.kind === 'rule')
                .slice()
                .sort((a, b) => this.rulePriority(b) - this.rulePriority(a));
        },

        ruleColumns() {
            return [
                { property: 'ruleId', label: this.$tc('merchant-quote-agent.assignment.columnRule') },
                { property: 'strategyId', label: this.$tc('merchant-quote-agent.assignment.columnStrategy') },
                { property: 'salesChannelId', label: this.$tc('merchant-quote-agent.assignment.columnSalesChannel') },
                { property: 'priority', label: this.$tc('merchant-quote-agent.assignment.columnPriority') },
            ];
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
        this.loadAssignments();
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

                const [result, versionedIds] = await Promise.all([
                    this.repository.search(criteria, Shopware.Context.api),
                    this.versionedStrategyIds(),
                ]);

                this.strategies = sortStrategies([...result]);
                this.versionedIds = versionedIds;
            } catch (error) {
                this.error = this.messageFor(error);
            } finally {
                this.isLoading = false;
            }
        },

        /**
         * Unlike strategies, assignment rows carry no archival flag -- the
         * design deliberately gives them none, since no decision references
         * one -- so this reads every row rather than filtering one out.
         */
        async loadAssignments() {
            this.assignmentError = null;

            try {
                const criteria = new Shopware.Data.Criteria(1, 100);

                const result = await this.assignmentRepository.search(criteria, Shopware.Context.api);

                this.assignments = [...result];

                // rule.name/priority need a second read -- see ruleNames'
                // own comment in data(). Skipped entirely when there is no
                // rule row, so a shop running only pins/splits pays nothing.
                // Left inside this try block on purpose: a failure here must
                // surface through assignmentError exactly like the search
                // above, not reject unobserved (Task 3's second fix round).
                const ruleIds = [...new Set(
                    this.assignments
                        .filter((row) => row.kind === 'rule' && row.ruleId)
                        .map((row) => row.ruleId),
                )];

                if (ruleIds.length > 0) {
                    const ruleCriteria = new Shopware.Data.Criteria(1, ruleIds.length);
                    ruleCriteria.addFilter(Shopware.Data.Criteria.equalsAny('id', ruleIds));

                    const rules = await this.ruleRepository.search(ruleCriteria, Shopware.Context.api);
                    const ruleNames = {};

                    rules.forEach((rule) => {
                        ruleNames[rule.id] = { name: rule.name, priority: rule.priority };
                    });

                    this.ruleNames = ruleNames;
                } else {
                    this.ruleNames = {};
                }
            } catch (error) {
                this.assignmentError = this.messageFor(error);
            }
        },

        /**
         * `company` is nullable and blank on every seeded customer in the test
         * shop -- verified live, not assumed -- because a private account
         * (SwagCommercial's default) never fills it in. `label-property`
         * alone would then render every option in the pin's customer select
         * as empty text. This is `sw-entity-single-select`'s `label-callback`
         * prop, so the row still names someone even before a merchant has a
         * real company account to pin.
         *
         * The null guard is not defensive-programming filler: the select
         * calls this with no argument for its own empty selection before a
         * customer is chosen, and skipping the guard threw in the console on
         * the very first render of an empty pin row.
         */
        customerLabel(customer) {
            if (!customer) {
                return '';
            }

            return customer.company || `${customer.firstName} ${customer.lastName}`.trim();
        },

        /** A blank pin row. Left unsaved until saveAssignment() sees a customer AND a strategy. */
        addPin() {
            const row = this.assignmentRepository.create(Shopware.Context.api);
            row.kind = 'pin';
            row.customerId = null;
            row.ruleId = null;
            row.weight = null;
            row.strategyId = null;
            row.salesChannelId = null;
            this.assignments.push(row);
        },

        /**
         * A blank rule row, field for field like addPin() above -- including
         * `customerId: null`, which isDuplicatePin's `other.kind === 'pin'`
         * filter relies on staying true for every non-pin row. Left unsaved
         * until saveAssignment() sees a rule AND a strategy; see isSavable's
         * `kind === 'rule'` branch, the guard against a dead rule binding.
         */
        addRule() {
            const row = this.assignmentRepository.create(Shopware.Context.api);
            row.kind = 'rule';
            row.customerId = null;
            row.ruleId = null;
            row.weight = null;
            row.strategyId = null;
            row.salesChannelId = null;
            this.assignments.push(row);
        },

        /** The rule's `priority`, or -Infinity while its metadata has not loaded -- see ruleNames. */
        rulePriority(row) {
            return this.ruleNames[row.ruleId]?.priority ?? Number.NEGATIVE_INFINITY;
        },

        /** Display form of the above: a dash rather than -Infinity for a row with no known priority yet. */
        rulePriorityLabel(row) {
            const priority = this.ruleNames[row.ruleId]?.priority;

            return typeof priority === 'number' ? String(priority) : '—';
        },

        /**
         * Shared by all three grids -- Tasks 4 and 5 call this by name, so it
         * stays generic rather than pin-specific. isSavable is the ONLY
         * completeness check: it already treats '' the same as null, which is
         * what a cleared sw-entity-single-select can hand back, so a second,
         * looser check here would only create a place for the two to disagree.
         *
         * The duplicate-pin check is separate from completeness on purpose --
         * a complete row can still be a duplicate -- and is gated on
         * `kind === 'pin'` rather than folded into isSavable, because it is
         * the admin's only defence against a gap the database itself cannot
         * close (see isDuplicatePin's docblock): MySQL accepts two global
         * pins for the same customer, since NULL is distinct from NULL in a
         * unique index. The customer's name is looked up here, not carried by
         * `row`, because naming which customer is a merchant-facing detail
         * this pure check has no business knowing.
         */
        async saveAssignment(row) {
            if (!isSavable(row)) {
                this.assignmentError = this.$tc('merchant-quote-agent.assignment.incomplete');

                return;
            }

            if (row.kind === 'pin' && isDuplicatePin(row, this.pins)) {
                // The refusal itself is load-bearing; the customer's name in it is a
                // courtesy. A failed lookup (deleted customer, unreadable under the
                // viewer's ACL) must still refuse the save, just with a generic label
                // instead of leaving this an unhandled rejection that shows nothing.
                let customerName = this.$tc('merchant-quote-agent.assignment.unknownCustomer');

                try {
                    const customer = await this.customerRepository.get(row.customerId, Shopware.Context.api);
                    customerName = this.customerLabel(customer);
                } catch {
                    // fall back to the generic label above
                }

                const key = row.salesChannelId
                    ? 'merchant-quote-agent.assignment.duplicatePinChannel'
                    : 'merchant-quote-agent.assignment.duplicatePinGlobal';

                this.assignmentError = this.$t(key, { customer: customerName });

                return;
            }

            this.assignmentError = null;
            await this.assignmentRepository.save(row, Shopware.Context.api);
            await this.loadAssignments();
        },

        /**
         * Shared by all three grids, same reason as saveAssignment(). A row
         * added by addPin()/addRule()/addSplitArm() and removed again before
         * ever being saved has no server-side counterpart to delete --
         * isNew() (not an `_isNew` property: this build's Entity marks itself
         * via EntityFactory#create -> markAsNew(), read back through the
         * isNew() method) is what tells the two cases apart.
         */
        async removeAssignment(row) {
            if (row.id !== undefined && !row.isNew()) {
                await this.assignmentRepository.delete(row.id, Shopware.Context.api);
            }

            await this.loadAssignments();
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

        hasVersion(strategy: StrategyLike) {
            return this.versionedIds.has(strategy.id);
        },

        /** The ids of every strategy with at least one version row. */
        async versionedStrategyIds() {
            const criteria = new Shopware.Data.Criteria(1, 1);
            criteria.addAggregation(Shopware.Data.Criteria.terms(VERSIONED_AGGREGATION, 'strategyId'));

            const result = await this.versionRepository.search(criteria, Shopware.Context.api);

            return versionedIds(result.aggregations);
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
            this.promptDraft = intent === 'duplicate' ? this.prompt : '';
            this.nameModalOpen = true;
        },

        async startRename(strategy) {
            await this.select(strategy);
            this.openNameModal('rename');
        },

        /**
         * Load the built-in's prompt BEFORE offering to name the copy. The
         * modal is seeded from `this.prompt`, so opening it alongside an
         * unawaited select() would race and copy whatever was in the editor
         * before -- an empty string on first use.
         */
        async duplicate(strategy) {
            await this.select(strategy);
            this.openNameModal('duplicate');
        },

        /** Rename needs a name; create and duplicate also need a first prompt. */
        nameModalIncomplete() {
            return this.nameDraft.trim() === '' || (this.nameModalIntent !== 'rename' && this.promptDraft.trim() === '');
        },

        async confirmName() {
            if (this.nameModalIncomplete()) {
                return;
            }

            this.error = null;
            this.isSaving = true;

            try {
                if (this.nameModalIntent === 'rename') {
                    await this.rename(this.nameDraft.trim());
                } else {
                    await this.create(this.nameDraft.trim(), this.promptDraft);
                }

                this.nameModalOpen = false;
            } catch (error) {
                this.error = this.messageFor(error);
            } finally {
                this.isSaving = false;
            }
        },

        async create(name: string, prompt: string) {
            const strategyId = Shopware.Utils.createId();

            await this.syncService.sync(newStrategySync(strategyId, Shopware.Utils.createId(), name, prompt));
            await this.load();

            const created = this.strategies.find((candidate) => candidate.id === strategyId);

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

            // An empty prompt is refused by the DAL with a 400; the button is
            // disabled for it, and this holds if it is ever reached anyway.
            if (this.selected === null || this.selectedIsBuiltIn || this.prompt.trim() === '') {
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
