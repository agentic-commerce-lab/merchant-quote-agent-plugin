<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

final readonly class BundleDecision
{
    public function __construct(
        public Band $band,
        public ?float $grantedDiscountPercent = null,
        public ?VolumeTier $appliedTier = null,
        public ?string $reason = null,
    ) {}
}
