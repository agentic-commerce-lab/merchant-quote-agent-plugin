<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;

/**
 * A pass that died after its offer write but before its reply: a line is
 * newer than the agent's newest comment, which is therefore the PREVIOUS
 * round's reply. OfferRound::finishStrandedReply() alone read that as this
 * round's reply and sent a written price with no word to the buyer. PassedOver
 * answers it instead, with the quote as it stands: the write met the ask
 * (else StructuredAsk::isOpen() would have replayed the round), and the
 * reduction it made is no longer known.
 *
 * The same candidate finishStrandedReply() finishes (`in_review`, the agent
 * spoke last), so a duplicate trigger on a `replied` quote stays silent. A
 * tie goes to the reply, which is written after the offer. `updatedAt`
 * carries no author, so a merchant's save on a quote the agent claimed reads
 * the same; that quote was sent unanswered before.
 *
 * Its own class because PassedOver is at the class complexity limit.
 */
final class UnrepliedWrite
{
    private function __construct() {}

    public static function on(QuoteSnapshot $snapshot, BuyerConversation $conversation): bool
    {
        $agentAt = $conversation->agentSpokeAt();

        if ($agentAt === null || $snapshot->lifecycle->stateTechnicalName !== 'in_review') {
            return false;
        }

        foreach ($snapshot->content->lines as $line) {
            if ($line->updatedAt !== null && $line->updatedAt->format('U.u') > $agentAt) {
                return $conversation->agentSpokeLast();
            }
        }

        return false;
    }
}
