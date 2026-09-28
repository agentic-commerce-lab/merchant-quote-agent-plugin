<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Policy\Epsilon;
use MerchantQuoteAgentPlugin\Policy\MoneyMath;

/**
 * A quote's goods in the space the buyer is shown (rounding control, spec
 * 2026-09-28): the quote's own tax space, which is what an Absolute discount
 * is denominated in (Bridge\Data\Discount) and what
 * QuoteTotals::buyerFacingTotal() adds up.
 *
 * `totalNet / netRatio` gives back the stored `totalPrice` each line was read
 * from (QuoteLineNet, via BuyerPriceSpace::fromNet()), so no second read of
 * the quote is needed.
 */
final readonly class BuyerFacingGoods
{
    private function __construct(
        /** The positive lines before any quote discount: what SwagCommercial takes an absolute discount off. */
        public float $gross,
        /** Everything in the buyer-facing total that is not a line: shipping, cash rounding. */
        public float $otherCosts,
        /**
         * Net lines with tax added on top (a `net` quote): the absolute
         * discount is net there and the tax is rounded per rate after it, so
         * no amount is guaranteed to land on a round total.
         */
        public bool $taxOnTop,
    ) {}

    public static function of(QuoteSnapshot $quote): self
    {
        $gross = 0.0;
        $net = 0.0;
        $lines = 0.0;
        foreach ($quote->content->lines as $line) {
            $stored = BuyerPriceSpace::fromNet($line->totalNet, $line->netRatio);
            $lines += $stored;
            if ($line->totalNet > 0.0) {
                $gross += $stored;
                $net += $line->totalNet;
            }
        }

        $total = $quote->totals->buyerFacingTotal();

        return new self(
            $gross,
            MoneyMath::roundMoney($total - $lines),
            abs($gross - $net) <= Epsilon::MONEY && $total > ($quote->totals->totalNet + Epsilon::MONEY),
        );
    }

    /**
     * What share of its net price every good keeps under an absolute discount
     * of `$absolute`. AbsolutePriceCalculator splits it over the tax rates by
     * gross value, which takes the same fraction off every good's net price.
     */
    public function factorAfter(float $absolute): float
    {
        return $this->gross > 0.0 ? 1 - ($absolute / $this->gross) : 1.0;
    }
}
