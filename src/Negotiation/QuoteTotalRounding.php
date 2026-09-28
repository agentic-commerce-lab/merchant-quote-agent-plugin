<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot as PolicySnapshot;
use MerchantQuoteAgentPlugin\Policy\Data\Rounding;
use MerchantQuoteAgentPlugin\Policy\Data\RoundingMode;
use MerchantQuoteAgentPlugin\Policy\Data\RoundingSkip;
use MerchantQuoteAgentPlugin\Policy\Epsilon;
use MerchantQuoteAgentPlugin\Policy\MoneyMath;
use MerchantQuoteAgentPlugin\Policy\RoundingStep;

/**
 * Rounding control, quote_total mode (spec 2026-09-28). A quote-wide write
 * becomes an absolute SwagCommercial discount that lands the buyer-facing
 * total on the next multiple of the merchant's step:
 * `goods before discount − (rounded target − other costs)`, in the quote's
 * own tax space.
 *
 * An absolute amount rather than an adjusted percentage, because per-rate
 * cent rounding keeps a percentage from landing on an exact figure. The
 * target is rounded UP, so the discount only shrinks and no limit can be
 * breached by it.
 */
final class QuoteTotalRounding
{
    private function __construct() {}

    /**
     * @param OfferWrite $write the unrounded write (OfferWrite::of())
     * @param QuoteSnapshot $reference the pre-write quote, read fresh
     * @param PolicySnapshot $live the same quote in policy terms
     * @param bool $buyersFigure the offer is the buyer's own ask (RoundingStep::isBuyersFigure())
     *
     * @return array{0: OfferWrite, 1: ?Rounding} the write to make, and what rounding did (null when it did not run)
     */
    public static function of(
        OfferWrite $write,
        QuoteSnapshot $reference,
        PolicySnapshot $live,
        QuoteLimits $limits,
        bool $buyersFigure,
    ): array {
        $step = $limits->stepFor(RoundingMode::QuoteTotal);
        $factor = $write->discountFactor;
        if ($step === null || $factor === null || $write->lines !== []) {
            return [$write, null];
        }

        $goods = BuyerFacingGoods::of($reference);
        $unrounded = MoneyMath::roundMoney(($goods->gross * $factor) + $goods->otherCosts);
        $target = RoundingStep::up($unrounded, $step);
        $absolute = MoneyMath::roundMoney($goods->gross + $goods->otherCosts - $target);
        $rounded = OfferWrite::absolute($absolute, $goods->factorAfter($absolute));
        $skipped = self::skipped($rounded, $live, $goods, $absolute, $buyersFigure);

        return [
            $skipped === null ? $rounded : $write,
            new Rounding(RoundingMode::QuoteTotal, $step, $unrounded, $target, $skipped),
        ];
    }

    /** Rule 5: a rounded total that landed more than a cent off its round figure. */
    public static function missed(?Rounding $rounding, float $landed): bool
    {
        return (
            $rounding !== null
            && $rounding->mode === RoundingMode::QuoteTotal
            && $rounding->skipped === null
            && abs($landed - $rounding->rounded) > Epsilon::MONEY
        );
    }

    /**
     * To-zero comes before standing-price for a reason beyond order:
     * SwagCommercial writes `-abs($value)`, so a negative amount would turn
     * into a discount, and nothing negative may get past this method. Its
     * other half keeps factorAfter() inside (0, 1]: an amount that takes the
     * whole of the goods leaves no share for PredictedWrite to price, so it
     * is never written either.
     *
     * ponytail: that other half is traced as to_zero too. Only an offer of
     * (nearly) 100% reaches it, which the verifier refuses anyway; give it
     * its own RoundingSkip if a merchant ever sees one.
     *
     * ponytail: standing_price is any PredictedWrite refusal. That includes
     * "total below its lines with no discount line", which the unrounded
     * write then trips too and escalates on, unrelated to rounding. Compare
     * the two writes' refusals if that trace reading ever misleads someone.
     */
    private static function skipped(
        OfferWrite $rounded,
        PolicySnapshot $live,
        BuyerFacingGoods $goods,
        float $absolute,
        bool $buyersFigure,
    ): ?RoundingSkip {
        return match (true) {
            $buyersFigure => RoundingSkip::BuyerFigure,
            $goods->taxOnTop => RoundingSkip::TaxOnTop,
            $absolute < Epsilon::MONEY || $absolute >= $goods->gross => RoundingSkip::ToZero,
            PredictedWrite::of($rounded, $live)->refusals !== [] => RoundingSkip::StandingPrice,
            default => null,
        };
    }
}
