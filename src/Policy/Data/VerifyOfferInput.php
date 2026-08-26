<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

/**
 * Post-modification offer verification input. `reference` must carry the
 * catalog/contract-priced state of the quote structure (before any pricing
 * round) — the authoritative baseline the saved offer is verified against.
 */
final readonly class VerifyOfferInput
{
    public function __construct(
        public QuoteSnapshot $reference,
        public QuoteSnapshot $final,
        public QuoteLimits $limits,
        public \DateTimeImmutable $now,
        public ?float $allowedExtraDiscountNet = null,
    ) {}
}
