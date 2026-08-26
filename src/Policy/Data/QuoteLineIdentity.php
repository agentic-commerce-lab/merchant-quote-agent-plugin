<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

final readonly class QuoteLineIdentity
{
    public function __construct(
        public string $lineItemId,
        public ?string $label = null,
        public ?string $productId = null,
        public ?string $unit = null,
    ) {}

    /** @throws \TypeError|\ValueError */
    public static function fromArray(array $data): self
    {
        return new self(
            lineItemId: RequiredShape::string($data, 'lineItemId'),
            label: OptionalShape::string($data, 'label'),
            productId: OptionalShape::string($data, 'productId'),
            unit: OptionalShape::string($data, 'unit'),
        );
    }
}
