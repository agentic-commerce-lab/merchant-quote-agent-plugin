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
     */
    public static function forRound(
        QuoteAgentSettings $settings,
        PolicySnapshot $snapshot,
        ?InterpretedAsk $ask,
    ): QuoteAgentSettings {
        $limits = $settings->policy->price;
        $asked = AskedDiscountCeiling::percent($snapshot, $ask?->interpretation);

        if ($asked === null || $asked >= $limits->maxDiscountPercent) {
            return $settings;
        }

        return $settings->withPolicy($settings->policy->withPrice($limits->withMaxDiscountPercent($asked)));
    }
}
