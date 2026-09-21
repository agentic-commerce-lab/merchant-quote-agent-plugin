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
