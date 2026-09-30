<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use Psr\Log\LoggerInterface;

/**
 * The asks the pipeline declines to answer itself, and hands to a human.
 *
 * Static and taking its collaborators as arguments, for ClarificationRound's
 * reason: NegotiationPipeline is already at mago's five-parameter constructor
 * limit, and the gate holds enough branches on its own to put the pipeline
 * class over the complexity threshold.
 */
final class AskGate
{
    /**
     * Null means the ask is inside the mandate. Only a comment can produce one
     * of these refusals, so a structured-only ask never reaches here — it
     * carries a target price and nothing else.
     *
     * @throws ModelUnavailable
     */
    public static function refuse(
        QuoteGatewayInterface $gateway,
        QuoteSnapshot $snapshot,
        InterpretedAsk $ask,
        OfferRound $round,
        LoggerInterface $logger,
    ): ?NegotiationPass {
        if ($ask->isStructural($snapshot)) {
            // Changing WHAT is being sold is outside a price-and-validity
            // mandate. Nothing downstream acts on these asks either —
            // CommentLineTargets reads lineChanges only for target prices —
            // so without this guard the buyer's real ask is silently dropped.
            $logger->info('The buyer asked to change the quote structurally; a human decides that.', [
                'quoteId' => $snapshot->identity->quoteId,
            ]);

            return $round->escalated(
                $gateway,
                $snapshot,
                QuoteEscalationReason::StructuralChangeRequested,
                $ask->promptHash,
                null,
            );
        }

        if ($ask->hasNonPriceAsk()) {
            // Shipping and payment terms are extracted and then go
            // nowhere: only `price` is composed into the proposal below, and
            // QuoteUpdate cannot write a delivery term anyway. Answering the
            // price half and dropping the rest silently is worse than saying
            // a human takes it, and promising shipping that never lands is
            // worse still.
            //
            // This gate is why the delivery and payment policies, their
            // deciders and their config no longer exist: nothing downstream
            // could ever be reached to honour them. The asks are still
            // EXTRACTED, and must stay so — that is what makes this
            // escalation possible instead of a silent drop.
            $logger->info('The buyer asked for a non-price term; a human decides that.', [
                'quoteId' => $snapshot->identity->quoteId,
            ]);

            return $round->escalated(
                $gateway,
                $snapshot,
                QuoteEscalationReason::NonPriceTermRequested,
                $ask->promptHash,
                null,
            );
        }

        if ($ask->needsClarification() && !StructuredAsk::isOpen($snapshot)) {
            // The model could not place the ask, so answering it means picking
            // a line at random. Before this gate the pass fell through with an
            // empty ask, landed in the grant band at roughly 0% and sent a
            // generic reply that advanced hasNewBuyerAsk() — so the buyer's
            // real question was answered with a no-op and then never asked
            // again. Which of ask-or-escalate happens is ClarificationRound's
            // call: the marker settles it, and it owns the marker.
            return ClarificationRound::handle($gateway, $snapshot, $ask, $round, $logger);
        }

        if ($ask->needsClarification()) {
            // An open per-line target — below the quoted price and not yet
            // answered, see StructuredAsk::isOpen() — is not a line picked at
            // random: it names the line AND the price, so the ambiguity this
            // gate exists for cannot be present. One buyer asked "what do you think about this
            // discount?" over three lines that each carried a requested price,
            // and the follow-up "the one I've requested" hit the marker and
            // escalated a 2.7% ask. The appliers read `requestedUnitPrice`
            // directly, so the pass below answers it with no help from the
            // interpretation.
            //
            // ponytail: the whole comment is treated as answered by the
            // structured target. A comment that asks for the targets AND
            // something genuinely ambiguous on top loses the second half
            // silently; split the question per line if that shows up.
            $logger->info('The buyer\'s comment was unclear, but an open requested price answers it.', [
                'quoteId' => $snapshot->identity->quoteId,
            ]);
        }

        return null;
    }
}
