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
     * The same interpretation with every price the buyer named — the per-line
     * targets and the quote-level budget — moved out of the buyer's space and
     * into the net one.
     *
     * `addProducts` targets are deliberately left alone: an added product makes
     * the pass structural (InterpretedAsk::isStructural()), so its price is
     * never priced against — it reaches a human as prose, in the space the
     * buyer wrote it.
     */
    public static function toNet(CommentInterpretation $interpretation, QuoteSnapshot $snapshot): CommentInterpretation
    {
        $ratios = [];
        foreach ($snapshot->content->lines as $line) {
            $ratios[$line->identity->lineItemId] = $line->netRatio;
        }

        return new CommentInterpretation(
            price: self::priceToNet($interpretation->price, $snapshot),
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

    /**
     * The quote-level budget, converted on the quote's own blended ratio
     * rather than a line's: a total the buyer names covers whatever the quote
     * covers, including shipping, and no single line's `netRatio` describes
     * that mix. `buyerFacingTotal()` is the figure the buyer was shown, which
     * is the space they typed their number in.
     */
    private static function priceToNet(PriceAsk $price, QuoteSnapshot $snapshot): PriceAsk
    {
        $gross = $snapshot->totals->buyerFacingTotal();

        if ($price->targetTotal === null || $gross <= 0.0 || $gross === $snapshot->totals->totalNet) {
            return $price;
        }

        return new PriceAsk(
            additionalDiscountPercent: $price->additionalDiscountPercent,
            bestPriceRequested: $price->bestPriceRequested,
            targetTotal: MoneyMath::roundMoney($price->targetTotal * ($snapshot->totals->totalNet / $gross)),
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
}
