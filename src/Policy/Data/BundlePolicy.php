<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

final readonly class BundlePolicy
{
    /** @param list<VolumeTier> $volumeTiers */
    public function __construct(
        public array $volumeTiers = [],
    ) {}

    /** @throws \TypeError|\ValueError */
    public static function fromArray(array $data): self
    {
        return new self(volumeTiers: ListShape::of($data, 'volumeTiers', VolumeTier::fromArray(...)));
    }
}
