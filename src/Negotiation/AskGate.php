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
                QuoteEscalationReason::NeedsHumanReview,
                $ask->promptHash,
                null,
            );
        }

        if ($ask->hasNonPriceAsk()) {
            // Shipping, payment terms and bundles are extracted and then go
            // nowhere: only `price` is composed into the proposal below, and
            // QuoteUpdate cannot write a delivery term anyway. Answering the
            // price half and dropping the rest silently is worse than saying
            // a human takes it — and promising shipping that never lands is
            // worse still, which is why this does not route through
            // NonPriceTermsDecider.
            $logger->info('The buyer asked for a non-price term; a human decides that.', [
                'quoteId' => $snapshot->identity->quoteId,
            ]);

            return $round->escalated(
                $gateway,
                $snapshot,
                QuoteEscalationReason::NeedsHumanReview,
                $ask->promptHash,
                null,
            );
        }

        if ($ask->needsClarification()) {
            // The model could not place the ask, so answering it means picking
            // a line at random. Before this gate the pass fell through with an
            // empty ask, landed in the grant band at roughly 0% and sent a
            // generic reply that advanced hasNewBuyerAsk() — so the buyer's
            // real question was answered with a no-op and then never asked
            // again. Which of ask-or-escalate happens is ClarificationRound's
            // call: the marker settles it, and it owns the marker.
            return ClarificationRound::handle($gateway, $snapshot, $ask, $round, $logger);
        }

        return null;
    }
}
