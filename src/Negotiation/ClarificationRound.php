<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use Psr\Log\LoggerInterface;

/**
 * Asks the buyer the question the model could not answer itself, once, and
 * hands the quote to a human if the ambiguity survives their reply.
 *
 * Stateless and static by necessity, not taste: NegotiationPipeline already
 * carries five constructor parameters and mago's excessive-parameter-list is an
 * error at five, so a sixth injected collaborator would fail the gate. Taking
 * `$round` as an argument reuses the OfferRound the pipeline already holds, so
 * the escalating branch goes through the same funnel as every other escalation
 * without the pipeline learning a new dependency.
 *
 * The questions are posted VERBATIM. The extract prompt promises the buyer sees
 * them as-is; rewording them through the model would pay for a call in order to
 * paraphrase a question into a different one. Nothing is appended, so there is
 * no template into which internal state could be interpolated — which matters,
 * because a quote comment is customer-facing copy (see QuoteEscalator).
 */
final class ClarificationRound
{
    public static function handle(
        QuoteGatewayInterface $gateway,
        QuoteSnapshot $snapshot,
        InterpretedAsk $ask,
        OfferRound $round,
        LoggerInterface $logger,
    ): NegotiationPass {
        $quoteId = $snapshot->identity->quoteId;

        if (ClarificationMarker::alreadyAsked($snapshot)) {
            $logger->info('The buyer\'s ask is still ambiguous after we already asked; a human takes it.', [
                'quoteId' => $quoteId,
            ]);

            return $round->escalated(
                $gateway,
                $snapshot,
                QuoteEscalationReason::NeedsHumanReview,
                $ask->promptHash,
                null,
            );
        }

        $logger->info('The buyer\'s ask was ambiguous; asking them rather than guessing.', [
            'quoteId' => $quoteId,
            'questionCount' => \count($ask->interpretation->clarificationQuestions),
        ]);

        // Comment first, then mark. If the mark fails the buyer sees the
        // question twice, which is mildly annoying; the reverse order would
        // mark a quote as asked without asking, silently turning the next
        // ambiguity into an escalation.
        $gateway->addComment($quoteId, implode("\n", $ask->interpretation->clarificationQuestions));
        $gateway->updateQuote($quoteId, new QuoteUpdate(customFields: ClarificationMarker::set()));

        return new NegotiationPass(NegotiationOutcome::Clarified, $ask->promptHash);
    }
}
