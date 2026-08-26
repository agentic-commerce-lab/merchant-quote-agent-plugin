<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

/**
 * The extraction output offered to decideNegotiation. `nonPrice` reuses
 * NegotiationAsks (TS structurally types NegotiationProposal as an
 * asks-compatible shape via `delivery`/`payment`/`bundle`; here that reuse is
 * explicit composition instead of duck typing).
 */
final readonly class NegotiationProposal
{
    public function __construct(
        public ?CommentInterpretation $price = null,
        public ?NegotiationAsks $nonPrice = null,
    ) {}

    /** @throws \TypeError|\ValueError|\CuyZ\Valinor\Mapper\MappingError */
    public static function fromArray(array $data): self
    {
        return new self(
            price: NestedShape::object($data, 'price', CommentInterpretation::fromArray(...)),
            nonPrice: ArrayMapper::mapObject(NegotiationAsks::class, $data),
        );
    }
}
