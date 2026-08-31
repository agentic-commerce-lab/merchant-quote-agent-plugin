<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing\Data;

/**
 * What only the message knows about THIS attempt: why the quote was queued,
 * and how many times servicing it has already been tried.
 *
 * A value object rather than two scalars on `service()`, which would put that
 * signature at the five-parameter cap with no room left.
 */
final readonly class PassContext
{
    public function __construct(
        public ServicingTriggerReason $reason,
        public int $attempt,
    ) {}
}
