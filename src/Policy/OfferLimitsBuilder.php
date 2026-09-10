<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\OfferLimits;

/**
 * The band the agent is allowed to operate within, reported back on every
 * authorization so a refused agent can re-offer inside it.
 *
 * Ported from `buildLimits`/`offerLimits` in
 * the retired TS agent (policy/negotiate-authorize.ts).
 */
final class OfferLimitsBuilder
{
    public function build(NegotiationPolicy $policy): OfferLimits
    {
        return new OfferLimits(maxDiscountPercent: $policy->price->maxDiscountPercent);
    }
}
