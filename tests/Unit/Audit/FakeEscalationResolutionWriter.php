<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\EscalationResolutionWriterInterface;

/** Captures what the subscriber decided to record, and can be told to fail. */
final class FakeEscalationResolutionWriter implements EscalationResolutionWriterInterface
{
    /** @var list<array{quoteId: string, state: string, at: \DateTimeImmutable}> */
    public array $calls = [];

    public ?\Throwable $throws = null;

    #[\Override]
    public function recordEscalationResolution(string $quoteId, string $state, \DateTimeImmutable $at): void
    {
        $this->calls[] = ['quoteId' => $quoteId, 'state' => $state, 'at' => $at];

        if ($this->throws !== null) {
            throw $this->throws;
        }
    }
}
