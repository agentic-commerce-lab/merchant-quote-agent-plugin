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
