<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

final readonly class BundleAsk
{
    public function __construct(
        public ?bool $requested = null,
    ) {}

    /** @throws \TypeError|\ValueError */
    public static function fromArray(array $data): self
    {
        return new self(requested: OptionalShape::bool($data, 'requested'));
    }
}
