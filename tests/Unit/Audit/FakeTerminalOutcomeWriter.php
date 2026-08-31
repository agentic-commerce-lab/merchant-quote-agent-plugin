<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\TerminalOutcomeWriterInterface;

/** Captures what the subscriber decided to record, and can be told to fail. */
final class FakeTerminalOutcomeWriter implements TerminalOutcomeWriterInterface
{
    /** @var list<array{quoteId: string, state: string, at: \DateTimeImmutable}> */
    public array $calls = [];

    public ?\Throwable $throws = null;

    #[\Override]
    public function recordTerminalOutcome(string $quoteId, string $state, \DateTimeImmutable $at): void
    {
        $this->calls[] = ['quoteId' => $quoteId, 'state' => $state, 'at' => $at];

        if ($this->throws !== null) {
            throw $this->throws;
        }
    }
}
