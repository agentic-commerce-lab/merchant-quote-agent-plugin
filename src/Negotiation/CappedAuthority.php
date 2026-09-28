<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Policy\AskedDiscountCeiling;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot as PolicySnapshot;
use MerchantQuoteAgentPlugin\Policy\GoodsFactor;

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
     * @param PolicySnapshot $live what the quote costs the buyer right now (SnapshotAdapter::toPolicy())
     */
    public static function forRound(
        QuoteAgentSettings $settings,
        PolicySnapshot $anchored,
        PolicySnapshot $live,
        ?InterpretedAsk $ask,
    ): QuoteAgentSettings {
        $limits = $settings->policy->price;
        $asked = AskedDiscountCeiling::percent($anchored, $ask?->interpretation);

        if ($asked === null) {
            return $settings;
        }

        $cap = min($limits->maxDiscountPercent, max($asked, self::standing($anchored, $live)));

        return $cap >= $limits->maxDiscountPercent
            ? $settings
            : $settings->withPolicy($settings->policy->withPrice($limits->withMaxDiscountPercent($cap)));
    }

    /**
     * The discount the buyer already holds, measured the way BOTH verifier
     * checks measure a hold: the total (DiscountTotalViolation) and the
     * deepest single line (LineNetViolation, on the price the buyer pays,
     * quote discount folded in). The line term matters because
     * MarginFloorClamp makes a round's write uneven by construction — a at 95,
     * b at 80 is 12.5% on the total and 20% on b, and a total-only cap
     * refuses the hold on b every round. Never negative.
     *
     * Structural reductions (a quantity cut from 10 to 5, a removed line)
     * shrink the live total against an anchored total that does not shrink
     * with them (#49's totals limitation), so they read as standing discount
     * and can lift the cap all the way to the merchant's maximum. That is only
     * safe because the same stale anchored total then fails the predicted
     * write closed (OfferApplier::rejected()) instead of letting it land.
     */
    private static function standing(PolicySnapshot $anchored, PolicySnapshot $live): float
    {
        $standing = $anchored->totalNet > 0.0
            ? (($anchored->totalNet - $live->totalNet) / $anchored->totalNet) * 100
            : 0.0;

        $reference = [];
        foreach ($anchored->lines as $line) {
            $reference[$line->lineItemId()] = $line->unitPriceNet;
        }

        $goodsFactor = GoodsFactor::of($live->lines);
        foreach ($live->lines as $line) {
            $original = $reference[$line->lineItemId()] ?? 0.0;
            if ($line->unitPriceNet > 0.0 && $original > 0.0) {
                $standing = max($standing, (($original - ($line->unitPriceNet * $goodsFactor)) / $original) * 100);
            }
        }

        return max(0.0, $standing);
    }
}
