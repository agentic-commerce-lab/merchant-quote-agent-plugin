<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\TraceWrite;
use MerchantQuoteAgentPlugin\Audit\TraceWriterInterface;

final class FakeTraceWriter implements TraceWriterInterface
{
    /** @var list<TraceWrite> */
    public array $events = [];

    public ?\Throwable $failure = null;

    #[\Override]
    public function write(TraceWrite $event): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $this->events[] = $event;
    }
}
