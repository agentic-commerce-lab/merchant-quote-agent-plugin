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
         * An absolute price named for the WHOLE quote ("can you do 3,500?"),
         * as the buyer wrote it — converted to net exactly once, alongside
         * every other extracted figure, by BuyerPriceSpace::toNet().
         */
        #[Assert\PositiveOrZero]
        public ?float $targetTotal = null,
    ) {}
}
