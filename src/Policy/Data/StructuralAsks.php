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
}
