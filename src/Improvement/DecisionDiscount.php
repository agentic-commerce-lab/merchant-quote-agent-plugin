<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

/**
 * The two numbers a harvested decision's discount is measured against: what
 * was granted and what the merchant's own band would have allowed. Both are
 * numbers, never the offer text that carried them.
 */
final readonly class DecisionDiscount
{
    public function __construct(
        public ?float $discountPercentGranted,
        public ?float $maxDiscountPercent,
    ) {}
}
