<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

/** Recording must never change the outcome of the delivery it describes. */
interface TraceWriterInterface
{
    public function write(TraceWrite $event): void;
}
