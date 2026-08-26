<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

final readonly class PaymentOfferLimits
{
    /** @param list<PaymentTerm> $allowedTerms */
    public function __construct(
        public array $allowedTerms = [],
        public ?int $maxNetDays = null,
        public ?float $minDepositPercent = null,
    ) {}
}
