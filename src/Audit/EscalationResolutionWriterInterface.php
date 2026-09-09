<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

/**
 * Stamps the escalation resolution onto a quote's newest pass. An interface so
 * EscalationResolutionSubscriber is unit-testable without a DAL, exactly as
 * TerminalOutcomeWriterInterface does for TerminalOutcomeSubscriber.
 */
interface EscalationResolutionWriterInterface
{
    public function recordEscalationResolution(string $quoteId, string $state, \DateTimeImmutable $at): void;
}
