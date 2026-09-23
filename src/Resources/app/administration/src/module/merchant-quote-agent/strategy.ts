/**
 * Pure helpers shared by the strategy selector and the strategies page.
 *
 * The three ids are the same constants as `MerchantQuoteAgentPlugin\Strategy\
 * BuiltInStrategies`. They are duplicated here rather than fetched because
 * they are frozen by definition -- they identify seeded rows -- and one
 * network round trip to learn three constants is not worth it.
 * strategy.check.mjs pins the duplication against the PHP source itself.
 */

export interface StrategyLike {
    id: string;
    name: string;
    archivedAt?: string | null;
}

export const BUILT_IN_IDS = [
    'c55bfc90a5fad6179a0fb17b90099e3d',
    '92be575ec330e77925e9a5323f1d8991',
    '793905231d61a815c66b139f759051dd',
] as const;

const SNIPPET_KEYS: Record<string, string> = {
    'c55bfc90a5fad6179a0fb17b90099e3d': 'marginDefender',
    '92be575ec330e77925e9a5323f1d8991': 'fastClose',
    '793905231d61a815c66b139f759051dd': 'relationshipBuilder',
};

export function isBuiltIn(id: string | null | undefined): boolean {
    return typeof id === 'string' && (BUILT_IN_IDS as readonly string[]).includes(id);
}

/** The snippet suffix for a built-in's translated name and description, or null. */
export function builtInSnippetKey(id: string): string | null {
    return SNIPPET_KEYS[id] ?? null;
}

/** Built-ins first, in the order the plugin ships them; everything else by name. */
export function sortStrategies<T extends StrategyLike>(strategies: T[]): T[] {
    const rank = (strategy: T): number => {
        const index = (BUILT_IN_IDS as readonly string[]).indexOf(strategy.id);

        return index === -1 ? BUILT_IN_IDS.length : index;
    };

    return [...strategies].sort((a, b) => rank(a) - rank(b) || a.name.localeCompare(b.name));
}

/**
 * The strategies a select may offer. Two kinds are left out, because assigning
 * either makes StrategyResolver throw UnknownStrategy and the servicing
 * preflight escalate every quote that assignment matches:
 *
 * - an archived strategy;
 * - one with no version yet, which has no prompt to resolve. `versionedIds`
 *   comes from the version table, since the strategy row itself cannot say.
 *
 * Order is left untouched: sortStrategies runs before or after, either order.
 */
export function selectableStrategies<T extends StrategyLike>(strategies: T[], versionedIds: ReadonlySet<string>): T[] {
    return strategies.filter((strategy) => !strategy.archivedAt && versionedIds.has(strategy.id));
}

/** The name of the `terms` aggregation over the version table's `strategyId`. */
export const VERSIONED_AGGREGATION = 'versioned';

/**
 * The strategy ids in that aggregation: every strategy with at least one
 * version. A missing aggregation reads as none, so a picker offers nothing
 * rather than something it cannot vouch for.
 */
export function versionedIds(aggregations: unknown): Set<string> {
    const buckets = (aggregations as Record<string, { buckets?: { key: string }[] }> | null | undefined)?.[
        VERSIONED_AGGREGATION
    ]?.buckets;

    return new Set((buckets ?? []).map((bucket) => bucket.key));
}

/**
 * One `_action/sync` payload writing a new strategy and its version 1. The
 * sync API extracts and validates every operation before it inserts anything,
 * then inserts them in one transaction -- so a refused prompt leaves no
 * strategy row behind, where two separate saves left one with no version.
 */
export function newStrategySync(strategyId: string, versionId: string, name: string, prompt: string): object {
    return {
        'merchant-quote-agent-strategy': {
            entity: 'merchant_quote_agent_strategy',
            action: 'upsert',
            payload: [{ id: strategyId, name }],
        },
        'merchant-quote-agent-strategy-version': {
            entity: 'merchant_quote_agent_strategy_version',
            action: 'upsert',
            payload: [{ id: versionId, strategyId, version: 1, prompt }],
        },
    };
}
