<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Ingress;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Http\QuoteTerminalState;

/**
 * May this session take an act from this sender at all — asked before the act
 * itself is examined, because none of these questions is about the act.
 *
 * The party rule is the whole authorization story for this route, so it is
 * worth stating plainly: the FIRST act on a chain pins the counterparty's
 * DID, and every act after it must carry the same one. A2CN authenticates the
 * writer as an agent (a DID-signed bearer token), never as the Shopware
 * customer who owns the quote — one Authorization header, one credential, and
 * demanding ours would mean no off-the-shelf A2CN buyer could reach us.
 *
 * What that leaves is bounded on purpose: someone holding a session id could
 * open a chain on a quote that has none. They cannot move a price — the
 * engine reads the Shopware snapshot, never an act — they cannot displace a
 * pinned buyer, and what they write is checked by every EvidenceCheck before
 * we sign anything into it.
 * ponytail: DID pinned by first act. Upgrade to "the DID must already be on
 * the quote" if the buyer's DID ever arrives on the quote-request payload.
 *
 * Two departures from the plan this was drafted against, both because the
 * obvious-looking helper answers a narrower question than this gate needs:
 *
 *   - CLOSED_STATES, not SessionOutcome::for(). SessionOutcome answers "which
 *     terminal RECORD does a finished session get" (spec 8.2) and is null for
 *     accepted/cancelled/withdrawn, because none of those states needs a
 *     wire record from that method — an accepted quote gets its record from
 *     $quote->acceptance elsewhere, and cancelled/withdrawn get none at all.
 *     Reusing it here would read those nulls as "still live" and let a buyer
 *     append acts to a quote whose deal is already closed, changing a
 *     transaction record a counterparty may already hold. This list is the
 *     actual lifecycle answer: every state a quote does not come back from.
 *     Duplicated rather than imported from Audit\TerminalOutcomeSubscriber,
 *     which owns the same list for its own reason — Protocol does not depend
 *     on Audit, and the two lists answer different questions that happen to
 *     agree.
 *
 *   - `count($chain->acts()) >= ActChain::MAX_ACTS`, not
 *     `$chain->exceedsLengthCap()`. That flag is set by ActChain::read() only
 *     when the stored chain ALREADY has MORE than MAX_ACTS keys — so a chain
 *     sitting at exactly the cap reads as false, we would accept one more
 *     act, and our own write would be the one that finally pushes the chain
 *     over the line into the permanently-unreadable state the flag exists to
 *     report. `acts()` is capped at MAX_ACTS by the same read(), so this
 *     comparison is exactly "is the chain full" — checked before we add to
 *     it, not after.
 */
final readonly class InboundActEligibility
{
    /**
     * A quote in one of these has finished negotiating. The same list as
     * Audit\TerminalOutcomeSubscriber::TERMINAL_STATES, duplicated rather
     * than shared: Protocol must not depend on Audit, and SessionOutcome is
     * a wire mapping (spec 8.2), not a lifecycle predicate — it answers
     * "which terminal record does this call for", which is a different
     * question with a deliberately narrower answer.
     */
    private const CLOSED_STATES = ['accepted', 'declined', 'expired', 'cancelled', 'withdrawn'];

    private function __construct() {}

    public static function refusal(
        Act $act,
        ActChain $chain,
        QuoteTerminalState $quote,
        string $sellerDid,
    ): ?InboundActRefusal {
        // A quote that reached an outcome is a negotiation that is over. Its
        // record is already derivable, and an act appended after it would
        // change a record a counterparty may already hold.
        if ($quote->acceptance !== null || $quote->expired || \in_array($quote->state, self::CLOSED_STATES, true)) {
            return new InboundActRefusal(409, 'session_closed');
        }

        // See the class docblock: ActChain::exceedsLengthCap() only reports a
        // chain that is ALREADY over the cap. acts() is capped at MAX_ACTS by
        // read(), so ">=" here is "the chain is full" — refused before our
        // own write would be the one to overflow it.
        if (\count($chain->acts()) >= ActChain::MAX_ACTS) {
            return new InboundActRefusal(409, 'chain_length_exceeded');
        }

        $pinned = $chain->firstForeignAct($sellerDid)?->senderDid();
        $sender = $act->senderDid();

        // Never our own DID: an act we did not sign, claiming to be from us,
        // is a forgery whatever else is true of it.
        if ($sender === $sellerDid || $pinned !== null && $sender !== $pinned) {
            return new InboundActRefusal(403, 'sender_did_not_party');
        }

        return null;
    }
}
