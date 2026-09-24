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
    /**
     * @mago-expect lint:excessive-parameter-list
     * A data carrier's promoted properties ARE its interface, and every
     * construction site uses named arguments (see OfferApplier), so the
     * call-site complexity this rule exists to catch does not arise here.
     */
    public function __construct(
        public QuoteSnapshot $reference,
        public QuoteSnapshot $final,
        public QuoteLimits $limits,
        public \DateTimeImmutable $now,
        public ?float $allowedExtraDiscountNet = null,
        /** @var array<string, float> lineItemId => effective minimum-margin floor net; empty checks nothing */
        public array $floors = [],
    ) {}
}
