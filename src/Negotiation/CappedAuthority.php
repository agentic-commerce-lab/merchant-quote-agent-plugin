<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Policy\AskedDiscountCeiling;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot as PolicySnapshot;

/**
 * The merchant's discount cap, tightened to what the buyer actually asked for.
 *
 * Applied to the SETTINGS rather than checked after the fact, because it has to
 * reach the negotiate prompt: OfferRound hands `$settings` to OfferProposer,
 * which composes AuthorityBrief from `$settings->policy`, so tightening here
 * tells the model the buyer's figure instead of the merchant's. That is the
 * substance of the fix — clamping only once the model had answered would leave
 * it anchoring on the higher cap and then be refused, turning a grantable ask
 * into an escalation.
 *
 * PriceOfferCheck and LinePriceOfferCheck bound against the same number, so the
 * total check and the per-line floor tighten with it and no new check is needed.
 *
 * Never below the concession the buyer already holds (design note
 * 2026-09-28): the ask is measured against the ORIGINAL prices, so a round-two
 * "5% off" after a 14% grant would otherwise cap the round at 5% of the
 * baseline — telling the model to take 9% back, and making the verifier, which
 * reads the same cap, flag every line still carrying the 14%. The floor lives
 * here rather than in AskedDiscountCeiling because this is the one place the
 * round's cap is derived; that class keeps answering only what the buyer asked.
 *
 * Its own class rather than a method on NegotiationPipeline for AskGate's
 * reason: the pipeline sits at mago's class-complexity ceiling, and this pushed
 * it over.
 */
final class CappedAuthority
{
    private function __construct() {}

    /**
     * The band decision is deliberately NOT capped — only the round is. That
     * decision answers "is this ask inside my authority", which the buyer's own
     * ask must not be allowed to redefine.
     *
     * @param PolicySnapshot $anchored the round's snapshot on the baseline (SnapshotAdapter::anchored())
     * @param float $liveTotalNet what the quote costs the buyer right now
     */
    public static function forRound(
        QuoteAgentSettings $settings,
        PolicySnapshot $anchored,
        float $liveTotalNet,
        ?InterpretedAsk $ask,
    ): QuoteAgentSettings {
        $limits = $settings->policy->price;
        $asked = AskedDiscountCeiling::percent($anchored, $ask?->interpretation);

        if ($asked === null) {
            return $settings;
        }

        $cap = min($limits->maxDiscountPercent, max($asked, self::standing($anchored, $liveTotalNet)));

        return $cap >= $limits->maxDiscountPercent
            ? $settings
            : $settings->withPolicy($settings->policy->withPrice($limits->withMaxDiscountPercent($cap)));
    }

    /**
     * The discount the buyer already holds, measured the way
     * DiscountTotalViolation measures the write: live total against the
     * anchored one. Never negative — a quote above its baseline holds nothing.
     */
    private static function standing(PolicySnapshot $anchored, float $liveTotalNet): float
    {
        if ($anchored->totalNet <= 0.0) {
            return 0.0;
        }

        return max(0.0, (($anchored->totalNet - $liveTotalNet) / $anchored->totalNet) * 100);
    }
}
