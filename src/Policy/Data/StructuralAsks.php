<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

final readonly class StructuralAsks
{
    /**
     * @param list<InterpretedLineChange> $lineChanges
     * @param list<InterpretedProductAddition> $addProducts
     */
    public function __construct(
        public array $lineChanges = [],
        public array $addProducts = [],
        public ?string $validityUntilIsoDate = null,
    ) {}

    /** @throws \TypeError|\ValueError */
    public static function fromArray(array $data): self
    {
        return new self(
            lineChanges: ListShape::of($data, 'lineChanges', InterpretedLineChange::fromArray(...)),
            addProducts: ListShape::of($data, 'addProducts', InterpretedProductAddition::fromArray(...)),
            validityUntilIsoDate: OptionalShape::string($data, 'validityUntilIsoDate'),
        );
    }
}
