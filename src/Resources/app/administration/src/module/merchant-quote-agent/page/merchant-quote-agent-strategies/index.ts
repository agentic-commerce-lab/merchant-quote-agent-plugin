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
import { groupedSplitShares, isDuplicatePin, isDuplicateRule, isSavable, mergeUnsaved } from '../../assignment.ts';

/**
 * Same ceiling and the same reason as PASS_LIMIT/QUOTE_LIMIT in the list
 * page: a shop with more assignment rows than this cannot see or edit the
 * rest here, though the resolver -- which reads server-side -- keeps
 * applying them. `assignmentTruncated` below reports it rather than
 * silently showing a partial grid. Raising this further, or paginating
 * instead, is the upgrade path if a shop ever needs more than 500.
 */
const ASSIGNMENT_LIMIT = 500;

/**
 * The snippet naming what an incomplete row of that kind is missing, keyed by
 * `row.kind`. One shared `incomplete` message used to cover all three grids
 * and told every merchant to "choose a customer", which is simply wrong on
 * the rule and split grids -- worst of all on the rule grid, where isSavable
 * is the only thing left blocking a dead rule_id-less binding (the CHECK
 * constraint cannot, see assignment.ts), so the guard fired correctly and
 * then explained itself incorrectly on the one path where that matters most.
 * `split` is added now, before Task 5's grid exists, so saveAssignment --
 * shared by all three -- does not need reopening for it later.
 */
const INCOMPLETE_SNIPPET_KEYS = {
    pin: 'merchant-quote-agent.assignment.incompletePin',
    rule: 'merchant-quote-agent.assignment.incompleteRule',
    split: 'merchant-quote-agent.assignment.incompleteSplit',
};

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
            // Server-side total from the last loadAssignments() search, used
            // only to detect truncation -- see assignmentTruncated.
            assignmentTotal: 0,
            // Its own field rather than reusing `error`: the library tab's
            // load() and this tab's loadAssignments() both run un-awaited from
            // created(), so sharing one field let a failure surface under the
            // wrong tab, or get overwritten before anyone saw it, because the
            // Assignments tab had no banner of its own to show it in.
            assignmentError: null,
            // ids from the LAST loadAssignments() search result, used only by
            // removeAssignment() to tell "never saved" apart from "saved a
            // moment ago" -- see mergeUnsaved's own docblock in assignment.ts.
            // isNew() cannot tell them apart on its own: Shopware never clears
            // it after a save, so a just-saved row still reports isNew() ===
            // true. Without this set, removeAssignment() would take the
            // "unsaved, splice locally" branch for that row and never send its
            // DELETE.
            assignmentServerIds: new Set(),
            // Incremented at the start of every loadAssignments() call and
            // captured locally there, the same captured-token pattern
            // select() already uses for newestVersion(). Two saves each
            // trigger their own reload; without this, an older response
            // landing after a newer one would overwrite `assignments` and
            // `assignmentServerIds` with a snapshot missing the row the newer
            // reload just saw -- see loadAssignments() itself.
            assignmentLoadToken: 0,
            // ids of rows with a save currently in flight, so a second edit
            // to the same row (e.g. double-clicking a new arm's weight
            // stepper, which emits update:model-value on every step) does
            // not fire a second POST while the row is still `isNew()` -- see
            // saveAssignment() and isRowSaving().
            assignmentSavingIds: new Set(),
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

        /** Exposed to the template so the truncation notice can name the ceiling without duplicating it. */
        assignmentLimit() {
            return ASSIGNMENT_LIMIT;
        },

        /** Whether the last loadAssignments() search left rows unread -- see ASSIGNMENT_LIMIT. */
        assignmentTruncated() {
            return this.assignmentTotal > ASSIGNMENT_LIMIT;
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

        /**
         * Rung 3. One flat grid, not one grid per sales channel: fewer
         * template branches, and each row already carries its own channel
         * column, so which peer group an arm belongs to is never hidden.
         * The percentages themselves are NOT flat, though -- groupedSplitShares
         * buckets by salesChannelId before computing shares, because a bucket
         * hash includes the channel (see the design doc), so weights are only
         * ever meaningful against the other arms in the SAME channel. Grouping
         * also leaves same-channel rows adjacent here, so the flat table still
         * reads as sectioned even without a heading per channel.
         */
        arms() {
            return groupedSplitShares(this.assignments.filter((row) => row.kind === 'split'));
        },

        armColumns() {
            return [
                { property: 'strategyId', label: this.$tc('merchant-quote-agent.assignment.columnStrategy') },
                { property: 'weight', label: this.$tc('merchant-quote-agent.assignment.columnWeight') },
                { property: 'percent', label: this.$tc('merchant-quote-agent.assignment.columnShare') },
                { property: 'salesChannelId', label: this.$tc('merchant-quote-agent.assignment.columnSalesChannel') },
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
         *
         * Replacing `this.assignments` outright with the search result would
         * discard every row a merchant has added but not yet saved -- see
         * mergeUnsaved's own docblock for the bug this closes. Only rows this
         * page itself still considers unsaved are carried over; a row this
         * page already persisted is always taken from the server, so a row
         * someone else deleted since the last load actually disappears.
         *
         * Returns whether THIS call actually applied a response: true once it
         * has written `assignments`/`assignmentServerIds` (or would have, but
         * a newer call already did it first -- see the token checks below;
         * that is a supersession, not a failure, so it is not reported as
         * one), false only when this call's own request rejected and its
         * catch block set assignmentError. saveAssignment() uses this to
         * decide whether the grid is actually back in sync with the server
         * before it overwrites assignmentError with a refusal message -- see
         * its own docblock.
         */
        async loadAssignments() {
            // Captured-token pattern, mirroring select()'s `requested`: two
            // overlapping calls (e.g. two saves in quick succession) each get
            // their own token, and only the response for the LATEST call is
            // still allowed to write `assignments`/`assignmentServerIds` --
            // otherwise an older response landing last would replace them
            // with a snapshot missing whatever the newer call just saw.
            const token = ++this.assignmentLoadToken;

            this.assignmentError = null;

            try {
                const criteria = new Shopware.Data.Criteria(1, ASSIGNMENT_LIMIT);
                criteria.setTotalCountMode(1);

                const result = await this.assignmentRepository.search(criteria, Shopware.Context.api);

                if (token !== this.assignmentLoadToken) {
                    // Superseded by a newer loadAssignments() call, which owns
                    // assignmentError/assignments now -- not a failure of
                    // THIS call.
                    return true;
                }

                this.assignmentServerIds = new Set(result.map((row) => row.id));
                this.assignments = mergeUnsaved([...result], this.assignments, (row) => row.isNew());
                this.assignmentTotal = result.total ?? this.assignments.length;

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

                    if (token !== this.assignmentLoadToken) {
                        return true;
                    }

                    const ruleNames = {};

                    rules.forEach((rule) => {
                        ruleNames[rule.id] = { name: rule.name, priority: rule.priority };
                    });

                    this.ruleNames = ruleNames;
                } else {
                    this.ruleNames = {};
                }

                return true;
            } catch (error) {
                if (token === this.assignmentLoadToken) {
                    this.assignmentError = this.messageFor(error);

                    return false;
                }

                // Stale: a newer call is already in flight or finished and
                // owns the error state now -- THIS call did not fail.
                return true;
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

        /**
         * A blank split-arm row, field for field like addPin()/addRule() above
         * -- including `customerId: null` and `ruleId: null`, which
         * isDuplicatePin's `other.kind === 'pin'` filter relies on staying
         * true for every non-pin row. Left unsaved until saveAssignment() sees
         * a strategy AND a positive weight; see isSavable's `kind === 'split'`
         * branch.
         */
        addArm() {
            const row = this.assignmentRepository.create(Shopware.Context.api);
            row.kind = 'split';
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
         * The duplicate-pin and duplicate-rule checks are separate from
         * completeness on purpose -- a complete row can still be a duplicate.
         * isDuplicatePin is gated on `kind === 'pin'` and isDuplicateRule on
         * `kind === 'rule'` rather than folded into isSavable, because each is
         * the admin's only defence against a gap the database itself cannot
         * close (see their own docblocks in assignment.ts): MySQL accepts two
         * global pins for the same customer, since NULL is distinct from NULL
         * in a unique index, and there is no unique index on rule_id at all.
         * The customer's/rule's name is looked up here, not carried by `row`,
         * because naming which one is a merchant-facing detail these pure
         * checks have no business knowing.
         *
         * Every refusal or failed write below restores a row that was already
         * on the server by reloading BEFORE showing the message -- see the
         * `assignmentServerIds.has(row.id)` branches. Without that, an edit
         * the server rejects (or never receives) stayed in `assignments`
         * showing the merchant's rejected value forever, which opened a real
         * hole: change saved global pin P1's customer to one that duplicates
         * P2, get refused, and the grid now shows that customer twice and the
         * original nowhere -- so isDuplicatePin, checking against that
         * corrupted in-memory list, no longer sees the original and lets a
         * FRESH pin for it through, leaving two real global pins in the
         * database. A row that was never saved keeps its local edit instead:
         * there is nothing on the server yet to restore, and reloading would
         * only discard what the merchant just typed -- ordinary, incomplete
         * data entry is not an error and is skipped silently for such a row
         * (see the isSavable branch below); only a previously saved row that
         * an edit has made incomplete gets the banner.
         *
         * The error is always set AFTER the reload, never before: reloading
         * calls loadAssignments(), which clears assignmentError as its first
         * line, so setting the message first would erase it immediately.
         *
         * The reload can itself fail (e.g. a network drop right then).
         * loadAssignments() already reports that through its own return value
         * and has already put its "could not refresh" message in
         * assignmentError -- see restoreThenSetError(), which every branch
         * below goes through instead of setting assignmentError directly.
         * Without that, the refusal/failure message below would silently
         * overwrite the load error while `assignments` is left showing the
         * stale, already-refused value -- and for a duplicate pin/rule, that
         * stale row is exactly what lets the same duplicate through again on
         * the next attempt, because isDuplicatePin/isDuplicateRule check
         * against `this.assignments`.
         */
        /** Whether `row` has a save in flight -- see saveAssignment() and assignmentSavingIds in data(). */
        isRowSaving(row) {
            return this.assignmentSavingIds.has(row.id);
        },

        /**
         * Shared by every refusal/failure branch in saveAssignment(): restore
         * `row` from the server first when there is a saved version to
         * restore, then show `message` -- but only if that restore actually
         * succeeded. If loadAssignments() failed, it already set the more
         * urgent "grid could not be refreshed" error; `message` is dropped
         * rather than stomping on it, because the merchant needs to know the
         * grid is stale more than they need the original refusal reason.
         */
        async restoreThenSetError(row, message) {
            if (this.assignmentServerIds.has(row.id)) {
                const reloaded = await this.loadAssignments();

                if (!reloaded) {
                    return;
                }
            }

            this.assignmentError = message;
        },

        async saveAssignment(row) {
            // A second edit to the same row while its save is still in
            // flight -- e.g. double-clicking a new arm's weight stepper,
            // which emits update:model-value on every step -- must not fire
            // a second POST: the row is still isNew() (Shopware never clears
            // it after a save), so a second create races the first and the
            // server refuses it with a 400. The row's inputs are also
            // disabled while isRowSaving() is true (see the template); this
            // guards the same window for an event already queued before that
            // takes effect.
            if (this.isRowSaving(row)) {
                return;
            }

            this.assignmentSavingIds.add(row.id);

            try {
                if (!isSavable(row)) {
                    if (this.assignmentServerIds.has(row.id)) {
                        await this.restoreThenSetError(row, this.$tc(INCOMPLETE_SNIPPET_KEYS[row.kind]));
                    }

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
                    const message = this.$t(key, { customer: customerName });

                    await this.restoreThenSetError(row, message);

                    return;
                }

                if (row.kind === 'rule' && isDuplicateRule(row, this.rules)) {
                    // ruleNames is already populated by loadAssignments() for
                    // every rule row this page has seen -- unlike the customer
                    // lookup above, no extra request is needed here, except the
                    // rare case of two brand-new rows picking the same rule
                    // before either has ever been saved, hence the fallback.
                    const ruleName = this.ruleNames[row.ruleId]?.name ?? this.$tc('merchant-quote-agent.assignment.unknownRule');
                    const key = row.salesChannelId
                        ? 'merchant-quote-agent.assignment.duplicateRuleChannel'
                        : 'merchant-quote-agent.assignment.duplicateRuleGlobal';
                    const message = this.$t(key, { rule: ruleName });

                    await this.restoreThenSetError(row, message);

                    return;
                }

                this.assignmentError = null;

                try {
                    await this.assignmentRepository.save(row, Shopware.Context.api);
                } catch (error) {
                    await this.restoreThenSetError(row, this.messageFor(error));

                    return;
                }

                // Closes the window between this save resolving and the reload
                // below finishing: a Remove click landing in that gap read
                // assignmentServerIds before loadAssignments() had repopulated
                // it, saw a row the server has never heard of, and skipped the
                // DELETE -- see removeAssignment()'s own docblock.
                this.assignmentServerIds.add(row.id);

                await this.loadAssignments();

                if (!this.assignmentServerIds.has(row.id)) {
                    // Core's sendChanges() (core/data/repository.data.ts)
                    // resolves instead of rejecting when the error response
                    // carries no `errors` body -- a network failure, an HTML 502
                    // -- so the save above did not throw even though nothing was
                    // written. loadAssignments() just rebuilt assignmentServerIds
                    // from a fresh server search, so its absence here is the
                    // server's own word that the write never landed, not a stale
                    // local copy: the optimistic add above is already gone,
                    // overwritten by that fresh set.
                    this.assignmentError = this.$tc('merchant-quote-agent.assignment.notSaved');
                }
            } finally {
                this.assignmentSavingIds.delete(row.id);
            }
        },

        /**
         * Shared by all three grids, same reason as saveAssignment(). A row
         * added by addPin()/addRule()/addSplitArm() and removed again before
         * ever being saved has no server-side counterpart to delete.
         *
         * Deciding that from `row.isNew()` alone is exactly the bug
         * mergeUnsaved's docblock (assignment.ts) closes for loadAssignments:
         * Shopware never clears `_isNew` after a save, so a row this page just
         * saved still reports `isNew() === true`. Checking `isNew()` here
         * would send that just-saved row down the "unsaved, splice locally"
         * branch below -- no DELETE would ever be sent for it, and the splice
         * would only hide it from this page until the next reload brought it
         * straight back. `assignmentServerIds` (populated by the last
         * loadAssignments()) is what actually distinguishes the two cases: a
         * row the server has never seen has no entry there; a saved row does,
         * regardless of what `isNew()` still claims.
         *
         * An unsaved row is spliced out of `this.assignments` directly
         * rather than removed by reloading: now that loadAssignments()
         * carries every still-unsaved row forward (mergeUnsaved), a reload
         * would resurrect the very row this just "removed".
         */
        async removeAssignment(row) {
            if (row.id !== undefined && this.assignmentServerIds.has(row.id)) {
                try {
                    await this.assignmentRepository.delete(row.id, Shopware.Context.api);
                } catch (error) {
                    // No reload here either -- see saveAssignment()'s own
                    // comment. A failed delete leaves the row exactly where
                    // it was, with the reason shown instead of silently
                    // vanishing from an unhandled rejection.
                    this.assignmentError = this.messageFor(error);

                    return;
                }

                await this.loadAssignments();

                return;
            }

            const index = this.assignments.indexOf(row);

            if (index !== -1) {
                this.assignments.splice(index, 1);
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
