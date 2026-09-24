<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit\Export;

use MerchantQuoteAgentPlugin\Audit\TraceEvent;

/** One outside-pass trace row as its own JSONL record. */
final class AnonymizedOutsideTrace
{
    private function __construct() {}

    /** @return array<string, mixed> */
    public static function of(TraceEvent $event, ExportPseudonym $pseudonym, bool $freeText): array
    {
        return [
            'record' => 'event',
            'id' => $pseudonym->of($event->id),
            'quote' => $pseudonym->of($event->quoteId),
            'customer' => $pseudonym->of($event->customerId),
            ...AnonymizedTrace::of($event, $freeText, $pseudonym->map([
                $event->id,
                $event->quoteId,
                $event->customerId,
            ])),
        ];
    }
}
