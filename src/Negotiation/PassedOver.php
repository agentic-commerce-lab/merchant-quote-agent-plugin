<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;

/**
 * What a pass with nothing to answer does instead: acknowledge the buyer, or
 * stay silent.
 *
 * A read comment that held no ask ("thanks", "ok", or a question the extract
 * model mis-read as empty) is acknowledged. Silence there dead-ended the
 * buyer: the comment had moved the quote to `change_requested`, only a reply
 * moves it back to `replied`, and over UCP a buyer can neither accept nor
 * counter until it does. It is still not an escalation --
 * #167 exists so that "thanks" never reaches a human.
 *
 * Silent `NothingToDo` is left for one case: no comment was read at all (a
 * duplicate trigger, or a reply stranded in `in_review`, which is finished
 * here as before). An escalation still awaiting a human never gets here:
 * MerchantHandover stands the pass down first, because moving the quote to
 * `replied` would make `SellerActPublisher::recordApproval()` read the
 * unreleased marker as a human standing behind terms nobody approved. Once a
 * merchant has sent an answer since the escalation, the terms are theirs and
 * the buyer's "thanks" is acknowledged like any other -- see
 * PendingEscalation::awaitsAHuman().
 *
 * Static and handed the round, like ClarificationRound, because the pipeline,
 * OfferRound and ReplyComposer are each at their class complexity limit.
 */
final class PassedOver
{
    private function __construct() {}

    public static function handle(
        QuoteGatewayInterface $gateway,
        QuoteSnapshot $snapshot,
        BuyerConversation $conversation,
        ?InterpretedAsk $ask,
        OfferRound $round,
    ): NegotiationPass {
        if ($ask === null) {
            $round->finishStrandedReply($gateway, $snapshot, $conversation);

            return new NegotiationPass(NegotiationOutcome::NothingToDo);
        }

        $round->acknowledge($gateway, $snapshot);

        return new NegotiationPass(NegotiationOutcome::Acknowledged, extractHash: $ask->promptHash);
    }
}
