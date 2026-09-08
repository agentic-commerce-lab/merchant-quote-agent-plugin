<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Emitter;

/** What one observation did. The values double as log markers. */
enum EmissionStatus: string
{
    case Inert = 'inert';
    case Unchanged = 'unchanged';
    case Violation = 'violation';
    case Emitted = 'emitted';
    case Failed = 'emission_failed';
}
