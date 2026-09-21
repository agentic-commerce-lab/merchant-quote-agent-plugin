<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;
use MerchantQuoteAgentPlugin\Policy\Data\InterpretedLineChange;
use MerchantQuoteAgentPlugin\Policy\Data\PriceAsk;
use MerchantQuoteAgentPlugin\Policy\Data\StructuralAsks;
use MerchantQuoteAgentPlugin\Policy\MoneyMath;

/**
 * The tax space the BUYER writes in, and the way back to the net one.
 *
 * A quote's stored prices — and so the storefront page and the UCP payload the
 * buyer reads — are in the quote's own tax space, `gross` on every quote of the
 * live shop. Everything behind the bridge is net instead: QuoteLineNet takes
 * the tax off on the way in, the policy layer's targets are net by definition,
 * and QuoteLineItemWriter divides by the same ratio to put an ask back.
 *
 * Between the two sits the extract model, reading a sentence a human typed. It
 * is shown the line table in the BUYER's space, so that the number in the prose
 * and the number in the table are the same money, and what it hands back is
 * therefore in the buyer's space too — and is converted here, once, before
 * anything downstream treats it as net.
 *
 * Without this, a buyer countering at 744.24 on a gross quote had that filed as
 * a net ask: `quote_line_item.requested_price` read back 885.65, one tax factor
 * ABOVE what they asked for, and the band decider then measured an ask above
 * the standing offer, clamped the requested discount to zero and granted 0.21%
 * on a quote where 14% had been asked for. Found live on sw-ag.dev quote 1037.
 *
 * @mago-expect lint:cyclomatic-complexity
 * Two independent figures now move out of the buyer's space — a per-line
 * target price and, since #164, a quote-level one — and each needs its own
 * null guard and its own "nothing to convert, leave it alone" branch: a
 * line's ratio can be 1.0 (no tax) same as the quote's, but the quote's own
 * ratio ALSO has to handle a null `totalGross` a line's precomputed ratio
 * never carries. Splitting the two conversions into their own methods (done)
 * is what keeps any ONE of them simple; the class total is the sum of two
 * genuinely separate, already-minimal conversions, not one branch that could
 * still be pulled apart.
 */
final class BuyerPriceSpace
{
    private function __construct() {}

    /**
     * A net price as the buyer sees it on their own quote.
     *
     * A line whose net IS its gross has no tax to add back, and a ratio of zero
     * has no inverse — both leave the number alone rather than divide by it.
     */
    public static function fromNet(float $net, float $netRatio): float
    {
        return $netRatio <= 0.0 || $netRatio === 1.0 ? $net : MoneyMath::roundMoney($net / $netRatio);
    }

    /**
     * The same interpretation with every per-line target price moved out of the
     * buyer's space and into the net one.
     *
     * `addProducts` targets are deliberately left alone: an added product makes
     * the pass structural (InterpretedAsk::isStructural()), so its price is
     * never priced against — it reaches a human as prose, in the space the
     * buyer wrote it.
     *
     * `price.targetTotal` (#164's quote-level target) moves the same way, off
     * the QUOTE's own ratio rather than a line's — there is no single line to
     * carry it.
     */
    public static function toNet(CommentInterpretation $interpretation, QuoteSnapshot $snapshot): CommentInterpretation
    {
        $hasLineChanges = $interpretation->structural->lineChanges !== [];
        $hasTargetTotal = $interpretation->price->targetTotal !== null;

        if (!$hasLineChanges && !$hasTargetTotal) {
            return $interpretation;
        }

        $ratios = [];
        foreach ($snapshot->content->lines as $line) {
            $ratios[$line->identity->lineItemId] = $line->netRatio;
        }

        return new CommentInterpretation(
            price: $hasTargetTotal ? self::priceToNet($interpretation->price, $snapshot) : $interpretation->price,
            structural: new StructuralAsks(
                lineChanges: array_map(static fn(InterpretedLineChange $c): InterpretedLineChange => self::lineToNet(
                    $c,
                    $ratios[$c->lineItemId] ?? 1.0,
                ), $interpretation->structural->lineChanges),
                addProducts: $interpretation->structural->addProducts,
                validityUntilIsoDate: $interpretation->structural->validityUntilIsoDate,
            ),
            clarificationQuestions: $interpretation->clarificationQuestions,
            humanReviewRequests: $interpretation->humanReviewRequests,
            negotiation: $interpretation->negotiation,
        );
    }

    private static function lineToNet(InterpretedLineChange $change, float $netRatio): InterpretedLineChange
    {
        if ($change->targetUnitPrice === null || $netRatio === 1.0) {
            return $change;
        }

        return new InterpretedLineChange(
            lineItemId: $change->lineItemId,
            quantity: $change->quantity,
            targetUnitPrice: MoneyMath::roundMoney($change->targetUnitPrice * $netRatio),
            remove: $change->remove,
        );
    }

    private static function priceToNet(PriceAsk $price, QuoteSnapshot $snapshot): PriceAsk
    {
        $ratio = self::quoteNetRatio($snapshot);

        return new PriceAsk(
            additionalDiscountPercent: $price->additionalDiscountPercent,
            bestPriceRequested: $price->bestPriceRequested,
            targetTotal: $price->targetTotal === null || $ratio === 1.0
                ? $price->targetTotal
                : MoneyMath::roundMoney($price->targetTotal * $ratio),
        );
    }

    /**
     * What the quote's own stored prices were multiplied by to reach net —
     * 1.0 on a net quote, or when there is no gross figure to divide by at
     * all (`??` folds that case into the same "nothing to divide by" branch
     * as an explicit zero, rather than a second condition).
     */
    private static function quoteNetRatio(QuoteSnapshot $snapshot): float
    {
        $gross = $snapshot->totals->totalGross ?? 0.0;

        return $gross <= 0.0 ? 1.0 : $snapshot->totals->totalNet / $gross;
    }
}
