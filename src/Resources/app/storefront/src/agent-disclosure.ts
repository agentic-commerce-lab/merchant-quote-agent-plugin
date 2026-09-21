/**
 * Who wrote a quote history entry, for the buyer-facing AI disclosure.
 *
 * Pure and DOM-free on purpose: agent-disclosure.check.mjs runs it under
 * `node --experimental-strip-types` with no browser and no Shopware runtime,
 * which is the only kind of JS check this project has.
 */

/**
 * The name the buyer sees in place of "Merchant" on an agent-written message.
 * Not translated: the plugin's buyer-facing copy is English throughout (see
 * QuoteEscalator::BUYER_MESSAGE), and this matches that bar rather than
 * raising it for one string.
 */
export const AGENT_ACTOR_NAME = 'AI Agent';

const isPresent = (value: unknown): boolean => {
    if (typeof value === 'string') {
        return value.length > 0;
    }

    // SwagCommercial's asRecord() yields {} for an absent association, so an
    // empty object means absent, not present.
    if (value !== null && typeof value === 'object') {
        return Object.keys(value as Record<string, unknown>).length > 0;
    }

    return Boolean(value);
};

/**
 * True when no party of any kind authored this entry, which is the agent.
 *
 * Three parties write a quote_comment and SwagCommercial gives each a
 * different column: the buyer gets customerId (plus employeeId for a B2B
 * employee), the merchant gets createdById, and the agent - writing from a
 * message handler under a SystemSource - gets none of them. This mirrors
 * QuoteComment::isAuthored() on the PHP side.
 *
 * Negative on every author rather than positive on the agent, because there is
 * no positive agent signal to read. That means anything genuinely unattributed
 * reads as the agent, which is the safe direction for a disclosure: labelling
 * an unattributed machine message as AI is better than leaving an AI message
 * labelled as a person.
 */
export function isAgentEntry(entry: Record<string, unknown>): boolean {
    return !isPresent(entry.customerId)
        && !isPresent(entry.employeeId)
        && !isPresent(entry.createdById)
        && !isPresent(entry.customer)
        && !isPresent(entry.employee);
}

export type HistoryActor = {
    name: string,
    initials: string,
    isCustomer: boolean,
};

/**
 * Sentinel returned by resolveActor() to mean "not an agent entry - call the
 * parent's getActor() instead". A pure function has no `super` to call itself,
 * so the override in main.ts is the one that owns that call; this just tells
 * it whether to make it.
 */
export const DELEGATE_TO_SUPER = Symbol('agent-disclosure:delegate-to-super');

/**
 * The logic behind `B2bQuoteHistoryItemPlugin.getActor()`'s override.
 *
 * Without this the buyer is told the MERCHANT wrote the agent's messages:
 * upstream resolves employee, then customer, then createdBy, and falls
 * through to the 'merchantComment' snippet - "Merchant" - when an entry has
 * none of the three, which is exactly an agent entry.
 *
 * `getInitials` is injected rather than imported because it is
 * `B2bQuoteHistoryItemPlugin`'s own instance method (initials formatting is
 * upstream's concern, not this plugin's), and this file has no plugin
 * instance to call it on.
 */
export function resolveActor(
    entry: Record<string, unknown>,
    getInitials: (name: string) => string,
): HistoryActor | typeof DELEGATE_TO_SUPER {
    if (!isAgentEntry(entry)) {
        return DELEGATE_TO_SUPER;
    }

    return {
        name: AGENT_ACTOR_NAME,
        initials: getInitials(AGENT_ACTOR_NAME),
        isCustomer: false,
    };
}

/**
 * The logic behind `B2bQuoteHistoryItemPlugin.isMerchantCommentOnlyEntry()`'s
 * override. Stops an agent message being merged into a merchant one.
 *
 * Upstream's predicate is `isCommentOnlyEntry() && !isCustomerOrEmployeeHistory()`,
 * and an agent entry satisfies both - "no author at all" is not "customer
 * or employee". mergeMerchantCommentHistories() would then fold it into
 * any merchant entry within HISTORY_MERGE_WINDOW_MS (15s), and
 * mergeCommentIntoHistoryEntry() builds {...target, comment: <agent text>,
 * createdById: target.createdById ?? ...}. The merged entry carries the
 * merchant's createdById and the agent's words, so resolveActor() above never
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
 *
 * `superResult` is the parent's own `isMerchantCommentOnlyEntry(entry)`,
 * passed in rather than recomputed here because only main.ts's override has
 * a `super` to call it through.
 */
export function suppressMerchantCommentMerge(entry: Record<string, unknown>, superResult: boolean): boolean {
    if (isAgentEntry(entry)) {
        return false;
    }

    return superResult;
}
