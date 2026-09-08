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
 * An absent sub-policy contributes no key at all — absent, never null: a
 * buyer reading `isset($bands['bundle'])` must see exactly "this shop declared
 * no volume authority", not "this shop declared null authority". The bundle
 * conversion itself lives in BundleBand, the same seam
 * Record\OfferSelection and Record\OptionalAct already use.
 *
 * Bundle is the only sub-policy left. Delivery and payment were published here
 * until it became clear the agent decides neither: AskGate escalates every
 * non-price ask, so the document was advertising authority nobody exercised.
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

        return self::withSubPolicies($bands, $policy);
    }

    /**
     * @param array<string, mixed> $bands
     *
     * @return array<string, mixed>
     */
    private static function withSubPolicies(array $bands, NegotiationPolicy $policy): array
    {
        // Delivery and payment bands were published here until the two
        // sub-policies were removed. They advertised terms the agent never
        // decided: AskGate escalates every non-price ask to a human, and
        // QuoteUpdate cannot write a delivery or payment term at all. A signed
        // document claiming otherwise is worse than a smaller one.
        $bundle = BundleBand::from($policy->bundle);
        if ($bundle !== null) {
            $bands['bundle'] = $bundle;
        }

        return $bands;
    }
}
