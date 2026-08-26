<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class InterpretedLineChange
{
    public function __construct(
        public string $lineItemId,
        public ?int $quantity = null,
        #[Assert\PositiveOrZero]
        public ?float $targetUnitPrice = null,
        public ?bool $remove = null,
    ) {}

    /** @throws \TypeError|\ValueError */
    public static function fromArray(array $data): self
    {
        return new self(
            lineItemId: RequiredShape::string($data, 'lineItemId'),
            quantity: OptionalShape::int($data, 'quantity'),
            targetUnitPrice: OptionalShape::float($data, 'targetUnitPrice'),
            remove: OptionalShape::bool($data, 'remove'),
        );
    }
}
