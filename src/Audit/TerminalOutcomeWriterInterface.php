<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

/**
 * The seam that keeps TerminalOutcomeSubscriber free of persistence: the
 * subscriber decides whether a transition is worth recording, this persists it.
 *
 * Separate from DecisionRecordWriterInterface on purpose. DecisionRecorder
 * needs only `write()`, and widening the shared interface would force
 * FakeDecisionWriter to implement a method the recorder tests do not care
 * about.
 */
interface TerminalOutcomeWriterInterface
{
    /**
     * Stamp the outcome onto the newest decision record for the quote. A quote
     * the agent never serviced has no record and is a silent no-op — inventing
     * one would be worse than recording nothing.
     */
    public function recordTerminalOutcome(string $quoteId, string $state, \DateTimeImmutable $at): void;
}
