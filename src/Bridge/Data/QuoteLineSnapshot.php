<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data;

/**
 * `unitPriceNet` is deliberately unconstrained in sign: Shopware-generated
 * lines (the quote-discount line) are legitimately negative.
 *
 * @mago-expect lint:excessive-parameter-list
 * Six promoted properties on a data carrier, not six arguments to a behaviour:
 * the threshold guards call sites that have to be read in order, and every one
 * of these is named at construction. The alternative — folding the money into
 * a value object — would rewrite every line read in the bridge, the adapter
 * and the fixtures to buy nothing.
 */
final readonly class QuoteLineSnapshot
{
    public function __construct(
        public QuoteLineIdentity $identity,
        public int $quantity,
        public float $unitPriceNet,
        public float $totalNet,
        public ?float $requestedUnitPrice = null,
        /**
         * What this line's stored prices were multiplied by to reach net — 1.0
         * on a net quote. Carried from QuoteLineNet so that the one place that
         * has to speak the buyer's own tax space, BuyerPriceSpace, can invert
         * it without a second read of the line.
         */
        public float $netRatio = 1.0,
    ) {}
}
