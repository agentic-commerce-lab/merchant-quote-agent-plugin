<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Record;

/** What the session was about. */
final readonly class RecordSubject
{
    public function __construct(
        public string $dealType,
        public string $currency,
        public string $subject,
        public string $subjectReference,
    ) {}
}
