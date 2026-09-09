<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;

/**
 * One order line's net unit price. Split out of OrderHistoryReads so that
 * class stays under mago's per-class method/complexity budget — the same
 * reason QuoteLineNet is its own file rather than a method on a quote read.
 *
 * `order_line_item.unitPrice` is stored in the ORDER's tax space, which is
 * `gross` on both of the test shop's orders — measured: a line's `totalPrice`
 * minus its own `calculatedTaxes` amount equals the order's `amountNet` to the
 * cent (6150.00 - 981.93 = 5168.07). So the tax comes off only when the order
 * says gross, and it comes off by subtracting the calculated amount rather
 * than dividing by a rate: rate-agnostic, and correct for a line with mixed
 * tax rules.
 *
 * The unit is derived from the line's NET TOTAL rather than from its stored
 * unit price, so unit * quantity reconciles with the total. This mirrors
 * QuoteLineNet, which does the same thing on the quote side and was validated
 * against 36 live quotes.
 *
 * Note that `calculatedTaxes` is the tax for the WHOLE LINE, not per unit —
 * dividing it by the quantity before subtracting would be a second, hidden
 * rounding step.
 */
final readonly class OrderLineNet
{
    /** @param string $taxStatus the owning order's `taxStatus`; only `gross` needs converting */
    public static function of(Entity $line, string $taxStatus): float
    {
        $total = (float) $line->get('totalPrice');
        $totalNet = round($total - self::taxIn($line, $taxStatus), precision: 2);

        // max() guards a division that Shopware's Required quantity field
        // should never permit.
        return round($totalNet / max((int) $line->get('quantity'), 1), precision: 2);
    }

    /** Only a gross-priced order has tax to remove; a net or tax-free one is already net. */
    private static function taxIn(Entity $line, string $taxStatus): float
    {
        $price = $line->get('price');

        // `price` is a Required CalculatedPriceField, so the instanceof narrows
        // the type for the analyzer rather than covering a real absence.
        return $taxStatus === CartPrice::TAX_STATE_GROSS && $price instanceof CalculatedPrice
            ? $price->getCalculatedTaxes()->getAmount()
            : 0.0;
    }
}
