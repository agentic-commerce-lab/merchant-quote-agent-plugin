<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class BundlePolicy
{
    /** @param list<VolumeTier> $volumeTiers */
    public function __construct(
        #[Assert\Valid]
        public array $volumeTiers = [],
    ) {}
}
