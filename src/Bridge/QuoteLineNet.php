<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;

/**
 * One quote line's prices in NET space.
 *
 * Shopware stores a line's `unitPrice`, `totalPrice` and `requestedPrice` in
 * the quote's own tax space, and that space is `gross` on all 36 quotes of the
 * live shop: summed `totalPrice` equals `amount_total`, never `amount_net`. The
 * bridge's read model is net throughout — `QuoteTotals::totalNet` comes from
 * `amountNet` — so a gross quote's lines get their tax taken off here.
 *
 * The tax comes off by subtracting the line's own `calculatedTaxes` rather than
 * by dividing by 1 + rate: rate-agnostic, correct for lines with mixed tax
 * rules, and it reproduces Shopware's arithmetic exactly — summed derived nets
 * equal `amount_net` to the cent on every one of those 36 quotes.
 */
final readonly class QuoteLineNet
{
    private function __construct(
        public float $total,
        public float $unitPrice,
        public ?float $requestedUnitPrice,
    ) {}

    /** @param string $taxStatus the owning quote's `taxStatus`; only `gross` needs converting */
    public static function of(Entity $lineItem, string $taxStatus): self
    {
        $total = (float) $lineItem->get('totalPrice');
        $totalNet = round($total - self::taxIn($lineItem, $taxStatus), precision: 2);
        $requested = $lineItem->get('requestedPrice');

        // A line's total is 0.0 only when its price is, and then so is its tax,
        // leaving no ratio to scale a buyer's ask by — so leave the ask as it
        // stands rather than dividing by zero.
        $netRatio = $total === 0.0 ? 1.0 : $totalNet / $total;

        return new self(
            total: $totalNet,
            // Derived from totalNet, not from `unitPrice`, so that unitPrice *
            // quantity reconciles with total. max() only guards a division that
            // Shopware's Required quantity field should never permit.
            unitPrice: round($totalNet / max((int) $lineItem->get('quantity'), 1), precision: 2),
            // The buyer types their ask into the storefront beside the quoted
            // unit price and through the same currency filter, so it lands in
            // the quote's tax space unconverted, and it carries no
            // calculatedTaxes of its own — hence this line's own net ratio.
            requestedUnitPrice: $requested === null ? null : round((float) $requested * $netRatio, precision: 2),
        );
    }

    private static function taxIn(Entity $lineItem, string $taxStatus): float
    {
        $price = $lineItem->get('price');

        // `price` is a Required CalculatedPriceField, so the instanceof narrows
        // the type for the analyzer rather than covering a real absence.
        return $taxStatus === CartPrice::TAX_STATE_GROSS && $price instanceof CalculatedPrice
            ? $price->getCalculatedTaxes()->getAmount()
            : 0.0;
    }
}
