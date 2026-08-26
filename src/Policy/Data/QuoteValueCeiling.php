<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Fix for issue #2(b): the value ceiling now carries its own currency, so a
 * multi-currency shop cannot compare a quote's total against a limit meant
 * for a different currency.
 */
final readonly class QuoteValueCeiling
{
    public function __construct(
        #[Assert\PositiveOrZero]
        public float $net,
        // Optional, empty by default: an unset currency means no currency check
        // is performed (the pre-fix behaviour), not that the ceiling is unset.
        #[Assert\Currency]
        public ?string $currencyIso = null,
    ) {}
}
