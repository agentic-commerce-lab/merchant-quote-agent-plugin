<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;

/** What the database says after we wrote, and whether it agrees with us. */
final readonly class AppliedOffer
{
    /** @param list<string> $violations */
    public function __construct(
        public bool $verified,
        public array $violations,
        public QuoteSnapshot $after,
    ) {}
}
