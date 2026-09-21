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
 * The questions are posted as the extract model wrote them, subject only to
 * the bounds below. Rewording them through the model would pay for a second
 * call in order to paraphrase a question into a different one, so this stays
 * a single call; nothing is appended either, so there is no template into
 * which internal state could be interpolated — which matters, because a quote
 * comment is customer-facing copy (see QuoteEscalator).
 *
 * No merchant tone reaches this text (issue #171 asked for it; #168 is where
 * PromptComposer::reply() grew a toneFrom() derived from the same strategy
 * setting). Threading it here would mean widening AskGate::refuse() and
 * AskInterpreter's extract() call — both outside this class — to carry
 * QuoteAgentSettings this far, for text that is already hard-bounded below.
 * That is a follow-up, not something a second model call should buy; shipping
 * the bound alone is the smaller, honest change.
 */
final class ClarificationRound
{
    /**
     * ponytail: a flat ceiling, not smarter batching. The extract prompt
     * already asks for one question per genuine ambiguity; real quotes ask
     * about one or two things at once. Three leaves headroom for a comment
     * that raises two or three separate points without letting a runaway
     * extraction turn into a buyer-facing wall of text.
     */
    private const MAX_QUESTIONS = 3;

    /**
     * ponytail: a hard character ceiling, not a smarter truncator — same
     * shape as PromptComposer::MAX_TONE_LENGTH. The extract prompt asks for
     * "one short ... question"; 240 characters comfortably fits any real one
     * (including a product name or two) while still capping an adversarial or
     * malformed extraction before it reaches the buyer.
     */
    private const MAX_QUESTION_LENGTH = 240;

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
                QuoteEscalationReason::UnplaceableAsk,
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
        $gateway->addComment($quoteId, self::bounded($ask->interpretation->clarificationQuestions));
        $gateway->updateQuote($quoteId, new QuoteUpdate(customFields: ClarificationMarker::set()));

        return new NegotiationPass(NegotiationOutcome::Clarified, $ask->promptHash);
    }

    /** @param list<string> $questions */
    private static function bounded(array $questions): string
    {
        $capped = array_map(self::capLength(...), \array_slice($questions, 0, self::MAX_QUESTIONS));

        return implode("\n", $capped);
    }

    private static function capLength(string $question): string
    {
        if (mb_strlen($question) <= self::MAX_QUESTION_LENGTH) {
            return $question;
        }

        return rtrim(mb_substr($question, 0, self::MAX_QUESTION_LENGTH)) . '…';
    }
}
