<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Mandate;

use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;

/**
 * The shop's negotiation policy, published in basis points (the A2CN `_bps`
 * convention: 1500 = 15%) — the merchant configures whole percents, so every
 * conversion here multiplies by 100.
 *
 * The grant boundary is INCLUSIVE, mirroring QuoteBandDecider: an ask at
 * exactly `autoGrantMaxBps` clears without a counter or an escalation.
 * `counterUpToBps`/`counterAtBps` are ADVISORY ONLY — the agent never
 * auto-counters an ask above the grant ceiling, it always escalates to a
 * human, and these fields publish the fixed counter that human's own
 * decision follows (QuoteBandDecider's counter band, #5), not a promise this
 * agent will make the offer itself.
 *
 * The price bands are all that is published. Delivery, payment and the volume
 * tiers each had a block here until it became clear the agent decides none of
 * them: AskGate escalates every non-price ask, so the document was advertising
 * authority nobody exercised.
 */
final class NegotiationBands
{
    private function __construct() {}

    /** @return array<string, mixed> */
    public static function fromPolicy(NegotiationPolicy $policy): array
    {
        $limits = $policy->price;
        $autoGrantMaxBps = (int) round($limits->maxDiscountPercent * 100);
        $bands = [
            'autoGrantMaxBps' => $autoGrantMaxBps,
            'counterAtBps' => (int) round(($limits->counterOfferMaxPercent ?? $limits->maxDiscountPercent) * 100),
            'escalateAboveBps' => $autoGrantMaxBps,
        ];
        if ($limits->counterOfferMaxPercent !== null) {
            $bands['counterUpToBps'] = (int) round($limits->counterOfferMaxPercent * 100);
        }

        return $bands;
    }
}
