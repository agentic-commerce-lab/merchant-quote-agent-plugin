<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class PriceAsk
{
    public function __construct(
        #[Assert\Range(min: 0, max: 100)]
        public ?float $additionalDiscountPercent = null,
        public ?bool $bestPriceRequested = null,
        /**
         * A budget the buyer names for the WHOLE quote ("max cost 2500"), as
         * opposed to the per-unit targets that land in
         * StructuralAsks::$lineChanges. Which of the two a number IS is the
         * extract model's call, made against the quote total it is shown.
         *
         * In the BUYER's tax space until BuyerPriceSpace::toNet() converts it,
         * like every other price the model hands back. Downstream it becomes
         * QuoteSnapshot::$buyerTargetNet, which the band decider and the
         * uniform pricer already read — so a quote-level budget needs no
         * decider of its own.
         */
        #[Assert\Positive]
        public ?float $targetTotal = null,
    ) {}
}
