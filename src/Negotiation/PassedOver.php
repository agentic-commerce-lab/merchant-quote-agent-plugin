<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Servicing\QuoteEscalator;

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
 * A fresh request with no comment at all is acknowledged too (QA-02): an
 * `open` quote (or one our own claim moved to `in_review`) with no comment
 * from anyone, no open storefront ask and no escalation marker is a
 * customer who submitted a quote request and is waiting for an answer.
 * Silence left it `open`, where they can neither accept nor counter. The
 * answer is the quote at its list prices.
 *
 * So is an offer written after the agent last spoke (UnrepliedWrite): a
 * write and a reply happen together or not at all.
 *
 * Silent `NothingToDo` is left for the rest of the no-comment case: a
 * duplicate trigger, or a reply stranded in `in_review`, which is finished
 * here as before. An escalation still awaiting a human never gets here:
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
            if (self::isFreshRequest($snapshot) || UnrepliedWrite::on($snapshot, $conversation)) {
                $round->acknowledge($gateway, $snapshot);

                return new NegotiationPass(NegotiationOutcome::Acknowledged);
            }

            $round->finishStrandedReply($gateway, $snapshot, $conversation);

            return new NegotiationPass(NegotiationOutcome::NothingToDo);
        }

        $round->acknowledge($gateway, $snapshot);

        return new NegotiationPass(NegotiationOutcome::Acknowledged, extractHash: $ask->promptHash);
    }

    /**
     * Nobody has said anything on the quote yet: no buyer, agent or merchant
     * comment. The fourth condition, no open storefront ask, is the caller's:
     * NegotiationPipeline only hands a pass here when StructuredAsk::isOpen()
     * is false. A duplicate trigger after the acknowledgement finds it
     * `replied` with our comment on it, so it is not answered twice.
     *
     * `in_review` counts too, unless an administration user moved it there.
     * ReplyComposer::acknowledge() claims an `open` quote before it comments,
     * and a pass that throws in between is retried unstamped
     * (ServiceQuoteHandler::servicePass()), so the retry finds `in_review`
     * and still nothing written. The agent's own `process` leaves no admin
     * transition (MerchantActionReader reads only rows with a user), so a
     * merchant's claim is told apart by `lastAdminTransitionTo`. An
     * integration's claim carries no user either and reads as ours.
     */
    private static function isFreshRequest(QuoteSnapshot $snapshot): bool
    {
        $lifecycle = $snapshot->lifecycle;
        $state = $lifecycle->stateTechnicalName;

        return (
            ($state === 'open' || $state === 'in_review' && $lifecycle->lastAdminTransitionTo !== 'in_review')
            && $snapshot->content->comments === []
            && ($lifecycle->customFields[QuoteEscalator::MARKER_KEY] ?? null) === null
        );
    }
}
