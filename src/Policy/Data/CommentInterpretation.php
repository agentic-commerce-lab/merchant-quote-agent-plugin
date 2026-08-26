<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

/**
 * Structured result of LLM comment understanding. Extraction only — every
 * value here is re-checked by code (offer verification against limits)
 * before the offer is sent.
 */
final readonly class CommentInterpretation
{
    /**
     * @param list<string> $clarificationQuestions
     * @param list<string> $humanReviewRequests
     */
    public function __construct(
        public PriceAsk $price = new PriceAsk(),
        public StructuralAsks $structural = new StructuralAsks(),
        public array $clarificationQuestions = [],
        public array $humanReviewRequests = [],
        public ?NegotiationAsks $negotiation = null,
    ) {}

    /** @throws \TypeError|\CuyZ\Valinor\Mapper\MappingError */
    public static function fromArray(array $data): self
    {
        $negotiation = OptionalShape::array($data, 'negotiation');

        return new self(
            price: ArrayMapper::mapObject(PriceAsk::class, $data),
            structural: ArrayMapper::mapObject(StructuralAsks::class, $data),
            clarificationQuestions: ListShape::ofStrings(
                $data,
                'clarificationQuestions',
                static fn(string $s): string => $s,
            ),
            humanReviewRequests: ListShape::ofStrings($data, 'humanReviewRequests', static fn(string $s): string => $s),
            negotiation: $negotiation === null ? null : ArrayMapper::mapObject(NegotiationAsks::class, $negotiation),
        );
    }
}
