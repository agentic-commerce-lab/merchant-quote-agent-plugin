<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

final readonly class OfferAuthorization
{
    /** @param list<string> $violations */
    public function __construct(
        public bool $approved,
        public array $violations,
        public OfferLimits $limits,
    ) {}
}
