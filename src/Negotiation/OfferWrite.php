<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\Discount;
use MerchantQuoteAgentPlugin\Bridge\Data\DiscountType;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLinePrice;
use MerchantQuoteAgentPlugin\Policy\Epsilon;
use MerchantQuoteAgentPlugin\Policy\MoneyMath;

/**
 * What OfferApplier writes for one offer: absolute unit prices for some lines,
 * then an absolute quote discount (or none). Worked out here so the applier's
 * write stays one straight path within the complexity gate.
 */
final readonly class OfferWrite
{
    /**
     * @param list<QuoteLinePrice> $lines unit prices to write; empty writes none
     * @param ?Discount $discount the quote discount to set; null leaves it as it is
     * @param ?float $discountFactor what share of its live net price each
     *     positive line costs once `$discount` is written (PredictedWrite);
     *     null exactly when `$discount` is. A percentage states it; an
     *     absolute amount does not, so whoever builds one works it out
     *     (QuoteTotalRounding).
     */
    private function __construct(
        public array $lines,
        public ?Discount $discount,
        public ?float $discountFactor = null,
    ) {}

    /**
     * A per-line offer writes its line prices and leaves the quote discount
     * alone; a quote-wide offer writes only the discount. A `$floored` offer
     * (MarginFloorClamp, spec 2026-09-24) prices every line with the old quote
     * discount folded in, so that discount goes to 0% — left on, it would stack
     * under the floor.
     *
     * A quote-wide offer writes `$quoteWidePercent`, the model's percentage
     * re-expressed on the live prices (QuoteWidePercent::of()),
     * never the raw one: that would stack on or replace a standing discount.
     */
    public static function of(
        ProposedOffer $offer,
        ?ProposedOffer $floored,
        QuoteSnapshot $reference,
        float $quoteWidePercent,
    ): self {
        if ($floored !== null) {
            $moved = self::moved($floored->price->linePricesNet ?? [], $reference);

            return $reference->totals->discount === null ? new self($moved, null) : self::percentage($moved, 0.0);
        }

        $lines = $offer->price->linePricesNet ?? [];

        return $lines !== [] ? new self($lines, null) : self::percentage([], $quoteWidePercent);
    }

    /**
     * Writes no price and leaves the quote discount as it is: the standing
     * offer already sits at the minimum-margin floor (FloorHold::holds()).
     */
    public static function hold(): self
    {
        return new self([], null);
    }

    /**
     * An absolute quote discount of `$value` in the quote's own tax space
     * (Bridge\Data\Discount), leaving every positive line at `$factor` of its
     * live net price. Rounding control's quote_total mode (spec 2026-09-28).
     */
    public static function absolute(float $value, float $factor): self
    {
        return new self([], new Discount(DiscountType::Absolute, $value), $factor);
    }

    /** @param list<QuoteLinePrice> $lines */
    private static function percentage(array $lines, float $percent): self
    {
        return new self($lines, new Discount(DiscountType::Percentage, $percent), 1 - ($percent / 100));
    }

    /**
     * A floored offer writes only the lines that move: rewriting an unchanged
     * price through the gross conversion can shift it a cent, which
     * OfferApplier's never-raise check escalates.
     *
     * @param list<QuoteLinePrice> $prices
     *
     * @return list<QuoteLinePrice> the prices that differ from the line's live unit price
     */
    private static function moved(array $prices, QuoteSnapshot $reference): array
    {
        $live = [];
        foreach ($reference->content->lines as $line) {
            $live[$line->identity->lineItemId] = MoneyMath::roundMoney($line->unitPriceNet);
        }

        return array_values(array_filter(
            $prices,
            static fn(QuoteLinePrice $price): bool => (
                abs($price->unitPriceNet - ($live[$price->lineItemId] ?? INF)) > Epsilon::RATE
            ),
        ));
    }
}
