<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data;

/**
 * A quote-level discount, in SwagCommercial's own `{type, value}` shape.
 *
 * Unlike everything else in this read model, `$value` is NOT net for every
 * type — its unit depends on `$type`:
 *
 * - `Percentage`: a percentage, so tax-state invariant. 5% off gross is 5% off
 *   net; nothing to convert either way.
 * - `Absolute`: an amount denominated in the QUOTE'S OWN TAX STATE, i.e. gross
 *   on a `taxStatus = gross` quote (all 36 on the live shop). Shopware makes
 *   that choice, not us: QuoteDiscountProcessor::calculateAbsoluteDiscount()
 *   hands the value to AbsolutePriceCalculator, which builds a
 *   QuantityPriceDefinition whose `$isCalculated` defaults to true, so
 *   GrossPriceCalculator::getUnitPrice() short-circuits and takes the number
 *   as-is. Write 100.00 meaning net and a gross quote's customer gets 84.03 of
 *   net relief.
 *
 * The bridge deliberately does NOT scale absolute values to compensate. Doing
 * so would contradict Shopware's own definition of the field and would break
 * the moment a shop runs in net mode, where the same value is already net.
 * A caller that needs a guaranteed net reduction should express it as
 * `Percentage`, which is all `src/Policy` emits today.
 */
final readonly class Discount
{
    public function __construct(
        public DiscountType $type,
        public float $value,
    ) {}
}
