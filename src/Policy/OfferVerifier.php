<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\VerifyOfferInput;

/**
 * Post-modification offer verification — the authoritative gate before an
 * offer is sent. The agent (LLM-informed) modifies and saves the quote; THIS
 * code then compares the actually-saved offer against the pre-pricing
 * reference snapshot and the merchant limits. Any violation keeps the offer
 * unsent and notifies the merchant. Never bypassed, never LLM-influenced.
 *
 * Ported from `verifyOffer` in the retired TS agent (policy/offer-verification.ts).
 */
final class OfferVerifier
{
    public function __construct(
        private readonly TotalsOfferVerifier $totals = new TotalsOfferVerifier(),
        private readonly LineOfferVerifier $lines = new LineOfferVerifier(),
        private readonly ExpirationOfferVerifier $expiration = new ExpirationOfferVerifier(),
        private readonly MarginFloorVerifier $floors = new MarginFloorVerifier(),
    ) {}

    /** @return list<string> */
    public function verify(VerifyOfferInput $input): array
    {
        return [
            ...$this->totals->verify(
                $input->reference,
                $input->final,
                $input->limits,
                $input->allowedExtraDiscountNet ?? 0.0,
            ),
            ...$this->lines->verify($input->reference, $input->final, $input->limits),
            ...$this->expiration->verify($input->final, $input->limits, $input->now),
            ...$this->floors->verify($input->final, $input->floors),
        ];
    }
}
