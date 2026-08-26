<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class OfferedPayment
{
    public function __construct(
        public ?PaymentTerm $paymentTerm = null,
        #[Assert\PositiveOrZero]
        public ?int $netDays = null,
        #[Assert\Range(min: 0, max: 100)]
        public ?float $depositPercent = null,
    ) {}
}
