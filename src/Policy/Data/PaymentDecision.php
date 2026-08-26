<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

final readonly class PaymentDecision
{
    public function __construct(
        public Band $band,
        public ?PaymentTerm $grantedTerm = null,
        public ?int $grantedNetDays = null,
        public ?float $grantedDepositPercent = null,
        public ?string $reason = null,
    ) {}
}
