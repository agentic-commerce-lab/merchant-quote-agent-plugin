<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Record;

/** Grouped so the record builders stay inside the five-parameter cap. */
final readonly class RecordParties
{
    public function __construct(
        public RecordParty $initiator,
        public RecordParty $responder,
    ) {}
}
