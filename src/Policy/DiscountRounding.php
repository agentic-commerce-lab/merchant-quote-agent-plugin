<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\Rounding;
use MerchantQuoteAgentPlugin\Policy\Data\RoundingMode;
use MerchantQuoteAgentPlugin\Policy\Data\RoundingSkip;

/**
 * Rounding control, discount_percent mode (spec 2026-09-28): the model's
 * quote-wide percentage floored to the merchant's step, before
 * OfferLevelMirror and OfferAuthorizer, so the per-line conversion, the
 * checks and the reply all see the rate that is written.
 *
 * Flooring means less discount, so no limit can be breached by it and no
 * check is added. The three rules that write the unrounded rate instead are
 * skipped(). A per-line answer is left alone: it is mostly the buyer's own
 * line prices.
 */
final class DiscountRounding
{
    private function __construct() {}

    /**
     * @param ?float $askedPercent the buyer's ask on the anchored total (AskedDiscountCeiling::percent()); null when they named no number
     * @param float $standingPercent what the buyer already holds, on the total and the deepest line (CappedAuthority::standing())
     *
     * @return array{0: ProposedOffer, 1: ?Rounding} the offer to authorize, and what rounding did (null when it did not run)
     */
    public static function offer(
        QuoteLimits $limits,
        ProposedOffer $offer,
        ?float $askedPercent,
        float $standingPercent,
    ): array {
        $step = $limits->stepFor(RoundingMode::DiscountPercent);
        $percent = $offer->price->discountPercent;
        if ($step === null || $percent === null || $offer->price->linePricesNet !== null) {
            return [$offer, null];
        }

        $rounded = RoundingStep::down($percent, $step);
        $rounding = new Rounding(
            RoundingMode::DiscountPercent,
            $step,
            $percent,
            $rounded,
            self::skipped($percent, $rounded, $askedPercent, $standingPercent),
        );

        return [
            new ProposedOffer(
                orderTotalNet: $offer->orderTotalNet,
                price: new OfferedPrice(
                    discountPercent: $rounding->written(),
                    referenceLines: $offer->price->referenceLines,
                ),
            ),
            $rounding,
        ];
    }

    /** Null when the rounded rate may be written; a figure rounding did not move needs no rule. */
    private static function skipped(float $unrounded, float $rounded, ?float $asked, float $standing): ?RoundingSkip
    {
        if (abs($unrounded - $rounded) <= Epsilon::RATE) {
            return null;
        }

        return match (true) {
            RoundingStep::isBuyersFigure($unrounded, $asked) => RoundingSkip::BuyerFigure,
            $rounded <= Epsilon::RATE => RoundingSkip::ToZero,
            $rounded < ($standing - Epsilon::RATE) => RoundingSkip::StandingPrice,
            default => null,
        };
    }
}
