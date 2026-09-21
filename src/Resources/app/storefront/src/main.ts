/**
 * Tells the buyer which quote messages an AI agent wrote.
 *
 * @internal-dependency
 * Shopware\Commercial B2bQuoteHistoryItemPlugin::getActor()
 * Shopware\Commercial B2bQuoteHistoryItemPlugin::isMerchantCommentOnlyEntry()
 * - both private by convention in an @internal storefront plugin, so a
 * SwagCommercial update can rename or restructure either. Same class of
 * coupling the bridge accepts for QuoteCommenter; see
 * Bridge/Commercial/SwagCommercialCommentWriter.php.
 *
 * Modern lane only. On released SwagCommercial there is no such plugin to
 * override, so the guard below leaves the registry untouched - which is why
 * the Twig banner, not this file, is the disclosure that reaches every lane.
 *
 * Why this isn't a plain `PluginManager.override(name, { getActor() {...} })`
 * object literal with `this.$super(...)`: neither exists for a cross-plugin
 * storefront override. `B2bQuoteHistoryItemPlugin` is registered by
 * SwagCommercial as a lazy `() => import(...)` loader, and every plugin's
 * storefront entry point is compiled as its own separate webpack target (see
 * shopware/storefront's webpack.config.js multi-compiler setup) - there is no
 * build-time import path from here into SwagCommercial's class, and no
 * per-plugin alias exposing one (unlike Administration's per-module
 * aliases). The storefront Plugin base class also has no `$super` method at
 * all - that is an Administration/Vue convention; storefront overrides use
 * real ES6 `class X extends Y { method() { super.method(); } }`, e.g.
 * SwagCommercial's own `B2bQuoteOffCanvasCart extends OffCanvasCartPlugin`.
 * And `PluginManager.override()`, for a same-name override, replaces the
 * registration outright (deregister + register) rather than merging methods
 * onto the existing prototype - that Object.assign merge path only runs for
 * `extend()` under a genuinely different name. So the only way to keep
 * SwagCommercial's behaviour and add two methods is to capture its own loader
 * before replacing it, resolve it ourselves once initialized, and subclass
 * the resolved class with real inheritance.
 */

import { AGENT_ACTOR_NAME, isAgentEntry } from './agent-disclosure.ts';

type HistoryEntry = Record<string, unknown>;

type HistoryActor = {
    name: string,
    initials: string,
    isCustomer: boolean,
};

type HistoryItemPluginInstance = {
    getActor(entry: HistoryEntry): HistoryActor,
    isMerchantCommentOnlyEntry(entry: HistoryEntry): boolean,
    getInitials(name: string): string,
};

type HistoryItemPluginClass = new (...args: unknown[]) => HistoryItemPluginInstance;

type HistoryItemPluginLoader = () => Promise<{ default: HistoryItemPluginClass }>;

const PLUGIN_NAME = 'B2bQuoteHistoryItemPlugin';

// The exact selector SwagCommercial registers the plugin under. PluginManager
// only replaces a registration under the selector it was originally
// registered with - a mismatched (or default `document`) selector makes the
// override silently no-op, which is why this is spelled out rather than
// omitted.
const PLUGIN_SELECTOR = '[data-b2b-quote-history-item-plugin]';

const PluginManager = window.PluginManager;

// Checked via getPluginList() rather than getPlugin() directly: getPlugin()
// warns to the console when the name isn't registered, and on the released
// SwagCommercial lane it never is - every storefront page load on that lane
// would otherwise print a benign-but-permanent warning.
//
// getPluginList() is NOT spreadable: PluginRegistry.keys() (vendor
// shopware/storefront's plugin.registry.js) reduces its internal Map into a
// plain `{}` rather than returning the Map itself, so it has no
// Symbol.iterator. `[...PluginManager.getPluginList()]` throws
// "is not iterable" - at module top level, on every storefront page load,
// before the override below ever runs. `in` reads a plain object's own and
// inherited keys, which is exactly what's needed here.
if (PLUGIN_NAME in PluginManager.getPluginList()) {
    // Read the currently-registered loader BEFORE override() replaces it.
    // override() deregisters the old registration and registers the new one
    // in its place (same name in, same name out), so this reference is the
    // only way to still reach SwagCommercial's own class afterwards. This
    // plugin's storefront bundle loads after SwagCommercial's QuoteManagement
    // bundle (see var/plugins.json's bundle order), so SwagCommercial's own
    // main.ts has always already registered B2bQuoteHistoryItemPlugin by the
    // time this file runs.
    const originalLoader = PluginManager.getPlugin(PLUGIN_NAME)?.get('class') as
        HistoryItemPluginLoader | HistoryItemPluginClass | undefined;

    if (originalLoader) {
        PluginManager.override(
            PLUGIN_NAME,
            async (): Promise<{ default: HistoryItemPluginClass }> => {
                // The captured value is whatever SwagCommercial registered:
                // normally still its lazy loader (resolution only happens
                // later, from PluginManager.initializePlugins()), but this
                // mirrors PluginManager's own resolved-vs-loader check (a
                // class has its own `prototype`; a loader function does not)
                // rather than assuming, in case another override already ran
                // first and left it resolved.
                const ParentPlugin = Object.getOwnPropertyDescriptor(originalLoader, 'prototype')
                    ? originalLoader as HistoryItemPluginClass
                    : (await (originalLoader as HistoryItemPluginLoader)()).default;

                class AgentDisclosureHistoryItemPlugin extends ParentPlugin {
                    /**
                     * Without this the buyer is told the MERCHANT wrote the agent's
                     * messages: upstream resolves employee, then customer, then
                     * createdBy, and falls through to the 'merchantComment' snippet -
                     * "Merchant" - when an entry has none of the three, which is
                     * exactly an agent entry.
                     */
                    getActor(entry: HistoryEntry): HistoryActor {
                        if (!isAgentEntry(entry)) {
                            return super.getActor(entry);
                        }

                        return {
                            name: AGENT_ACTOR_NAME,
                            initials: this.getInitials(AGENT_ACTOR_NAME),
                            isCustomer: false,
                        };
                    }

                    /**
                     * Stops an agent message being merged into a merchant one.
                     *
                     * Upstream's predicate is `isCommentOnlyEntry() && !isCustomerOrEmployeeHistory()`,
                     * and an agent entry satisfies both - "no author at all" is not "customer
                     * or employee". mergeMerchantCommentHistories() would then fold it into
                     * any merchant entry within HISTORY_MERGE_WINDOW_MS (15s), and
                     * mergeCommentIntoHistoryEntry() builds {...target, comment: <agent text>,
                     * createdById: target.createdById ?? ...}. The merged entry carries the
                     * merchant's createdById and the agent's words, so getActor() above never
                     * sees the signal - it is destroyed before render.
                     *
                     * In practice: a merchant editing the quote in the administration within
                     * 15s of an agent reply would see the agent's text under a named human,
                     * with no disclosure at all.
                     *
                     * The cost is a slightly longer timeline - an agent pass that both changes
                     * the quote and comments now renders two articles instead of one. Worth it
                     * for a signal that cannot be silently lost.
                     *
                     * The other merge path, mergeAddedStatusCommentHistories(), is gated on
                     * action === 'request', which an agent comment never is. It needs no
                     * override.
                     */
                    isMerchantCommentOnlyEntry(entry: HistoryEntry): boolean {
                        if (isAgentEntry(entry)) {
                            return false;
                        }

                        return super.isMerchantCommentOnlyEntry(entry);
                    }
                }

                return { default: AgentDisclosureHistoryItemPlugin };
            },
            PLUGIN_SELECTOR,
        );
    }
}
