<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

final readonly class BundlePolicy
{
    /** @param list<VolumeTier> $volumeTiers */
    public function __construct(
        public array $volumeTiers = [],
    ) {}
}
