<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

/**
 * The band the agent is allowed to operate within — what it's told before it
 * proposes an offer. The same number OfferAuthorizer enforces afterwards, and
 * the one AskedDiscountCeiling tightens to the buyer's own ask.
 */
final readonly class OfferLimits
{
    public function __construct(
        public float $maxDiscountPercent,
    ) {}
}
