<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class PaymentPolicy
{
    /** @param list<PaymentTerm> $allowedTerms */
    public function __construct(
        public array $allowedTerms = [],
        #[Assert\PositiveOrZero]
        public ?int $maxNetDays = null,
        #[Assert\Range(min: 0, max: 100)]
        public ?float $minDepositPercent = null,
    ) {}
}
