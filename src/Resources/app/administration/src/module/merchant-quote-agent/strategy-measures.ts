/**
 * The dashboard's success measures, grouped by the negotiation strategy that
 * produced them.
 *
 * Every figure here comes from calling the functions in measures.ts on a
 * subset of the passes — nothing is recomputed and nothing is copied. That is
 * the point: the per-strategy table and the overall tiles agree because they
 * run the same code, and a fix to a measure fixes both.
 *
 * This file states no winner. B2B quote volume is small enough that a
 * quarter's difference between two strategies is usually noise, so every row
 * carries its N and the template shows it.
 *
 * Rows are keyed by STRATEGY, not by strategy version. `StrategyVersion`'s
 * contract is that editing a strategy appends a version and never rewrites
 * one, so a single posture accumulates versions over time; splitting the
 * table on them would fragment an already-small N across rows that are all
 * still labelled with the same strategy name. `attributeStrategy` keeps
 * attributing at the version level — that is the correct audit-level
 * granularity, and it is what a decision row actually records — but
 * `strategyRows` rolls each attributed version up to its strategy afterwards,
 * via the `strategyOf` lookup, and reports the version spread it collapsed
 * as `versions` instead of silently discarding it.
 */

import {
    autoExecutionRate,
    dealCycleTime,
    escalationResolution,
    priceRetention,
    splitDeals,
    tokensPerNegotiation,
} from './measures.ts';
import { answeredTheBuyer, foldToQuotes } from './decision.ts';

/**
 * Which strategy a quote belongs to, from all of that quote's passes.
 *
 * The last answered pass wins, because `priceRetention` measures `netBefore`
 * against `latestAnswered.totalNetAfter` — that pass is the one that set the
 * price being measured. Passes arrive newest-first, so the FIRST answered pass
 * in the array is the last one chronologically.
 *
 * A quote that only ever escalated has no answered pass. Falling back to the
 * newest pass of any kind is not a nicety: dropping those quotes would take
 * every escalation out of every group, and `autoExecutionRate` would then
 * report 100% for every strategy — the exact failure its own docblock exists
 * to prevent.
 *
 * `mixed` counts a quote whose passes name more than one strategy, which
 * happens when a merchant switches strategies mid-negotiation. Such a quote is
 * still attributed, never dropped, but the template reports how many there are
 * so the reader can judge how much of the comparison is contaminated.
 */
export function attributeStrategy(quotePasses: any[]): { strategyVersionId: string | null; mixed: boolean } {
    const named = (quotePasses ?? [])
        .map((pass) => pass.strategyVersionId ?? null)
        .filter((id): id is string => typeof id === 'string' && id !== '');

    const answered = (quotePasses ?? []).find((pass) => answeredTheBuyer(pass.outcome ?? null) && pass.strategyVersionId);

    return {
        strategyVersionId: answered?.strategyVersionId ?? named[0] ?? null,
        mixed: new Set(named).size > 1,
    };
}

/**
 * Every pass, grouped by its quote id — the fold `groupPassesByStrategy` and
 * `strategyRows`'s mixed-quote count both need, kept in one place so the
 * duplication gate has nothing to flag.
 */
function groupByQuoteId(passes: any[]): Map<string, any[]> {
    const byQuote = new Map<string, any[]>();

    (passes ?? []).forEach((pass) => {
        const seen = byQuote.get(pass.quoteId);

        if (seen) {
            seen.push(pass);

            return;
        }

        byQuote.set(pass.quoteId, [pass]);
    });

    return byQuote;
}

/**
 * Every pass, bucketed by its QUOTE's strategy rather than its own.
 *
 * A quote's rounds must not be split across groups: a group holding round two
 * but not round one would measure a fragment of a negotiation and report it as
 * a whole one.
 */
export function groupPassesByStrategy(passes: any[]): Map<string | null, any[]> {
    const grouped = new Map<string | null, any[]>();

    groupByQuoteId(passes).forEach((quotePasses) => {
        const { strategyVersionId } = attributeStrategy(quotePasses);
        const bucket = grouped.get(strategyVersionId);

        if (bucket) {
            bucket.push(...quotePasses);

            return;
        }

        grouped.set(strategyVersionId, [...quotePasses]);
    });

    return grouped;
}

/** A strategy version, resolved to the strategy it belongs to. */
type ResolvedStrategy = { strategyId: string; name: string | null; version: number };

/**
 * A pass's own `strategyVersionId`, resolved through `strategyOf` — or null,
 * for a pass that carries no version id or an id `strategyOf` cannot place.
 */
function resolveOwnStrategy(
    pass: any,
    strategyOf: (strategyVersionId: string | null) => ResolvedStrategy | null,
): ResolvedStrategy | null {
    return pass.strategyVersionId ? strategyOf(pass.strategyVersionId) : null;
}

/**
 * One row per strategy, each carrying the same five measures the overall
 * tiles show, plus the counts that keep the row honest.
 *
 * The baseline is shared, and genuinely so: it is computed ONCE from every
 * pass across every strategy, not from this group's quotes alone. A quote
 * folded under a DIFFERENT strategy is still an agent-touched quote — it is
 * not "a quote the agent never touched" just because it is not in this
 * group — so building the baseline per group (everything not in THIS group's
 * fold) would leak other strategies' agent-negotiated deals into this row's
 * baseline. Only the untouched-by-any-strategy set qualifies as baseline, and
 * that set is the same for every row. Each group's agent side is then that
 * global agent list filtered down to the quote ids this group actually
 * folded.
 *
 * `groupPassesByStrategy` still keys its groups by the ATTRIBUTED VERSION —
 * that function is also used on its own, at the audit granularity. Here, each
 * of those version-keyed groups is rolled up one more step: the version id
 * is resolved to a strategy id via `strategyOf`, and every group that
 * resolves to the same strategy is merged into one row. Two versions of one
 * strategy therefore land in the same row instead of producing two rows both
 * labelled with that strategy's name.
 */
export function strategyRows(
    passes: any[],
    quoteRows: any[],
    orderDates: Map<string, string>,
    slaHours: number | null,
    strategyOf: (strategyVersionId: string | null) => ResolvedStrategy | null,
): any[] {
    const allFoldedByQuoteId = new Map(foldToQuotes(passes ?? []).map((quote) => [quote.quoteId, quote]));
    const { agent: globalAgent, baseline } = splitDeals(quoteRows ?? [], orderDates, allFoldedByQuoteId);

    const rolled = new Map<string | null, any[]>();

    groupPassesByStrategy(passes).forEach((group, versionId) => {
        const strategyId = versionId === null ? null : (strategyOf(versionId)?.strategyId ?? null);
        const bucket = rolled.get(strategyId);

        if (bucket) {
            bucket.push(...group);

            return;
        }

        rolled.set(strategyId, [...group]);
    });

    return [...rolled.entries()].map(([strategyId, group]) => {
        const folded = foldToQuotes(group);
        const groupQuoteIds = new Set(folded.map((quote) => quote.quoteId));
        const agent = globalAgent.filter((deal) => groupQuoteIds.has(deal.quoteId));

        // A quote spanning two STRATEGIES is contamination; spanning two
        // VERSIONS of the same strategy is just its prompt getting edited
        // mid-negotiation, and must not be flagged as if it were the same
        // thing.
        const mixedQuotes = [...groupByQuoteId(group).values()].filter((quotePasses) => {
            const strategyIds = new Set(
                quotePasses
                    .map((pass) => resolveOwnStrategy(pass, strategyOf)?.strategyId)
                    .filter((id): id is string => id !== undefined),
            );

            return strategyIds.size > 1;
        }).length;

        // Only versions that actually resolve to THIS row's strategy count —
        // a mixed quote's foreign-strategy pass must not leak its version
        // number into this row's spread.
        const ownVersions = strategyId === null
            ? []
            : group
                .map((pass) => resolveOwnStrategy(pass, strategyOf))
                .filter((resolved): resolved is ResolvedStrategy => resolved !== null && resolved.strategyId === strategyId);

        return {
            strategyId,
            name: ownVersions[0]?.name ?? null,
            versions: [...new Set(ownVersions.map((resolved) => resolved.version))].sort((a, b) => a - b),
            quotes: folded.length,
            mixedQuotes,
            autoExecution: autoExecutionRate(folded),
            escalations: escalationResolution(group, slaHours),
            priceRetention: priceRetention(agent, baseline),
            cycleTime: dealCycleTime(agent, baseline),
            tokens: tokensPerNegotiation(group),
        };
    });
}
