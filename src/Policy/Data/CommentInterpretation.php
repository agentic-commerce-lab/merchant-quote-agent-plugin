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

    /** @throws \TypeError|\ValueError */
    public static function fromArray(array $data): self
    {
        return new self(
            price: PriceAsk::fromArray($data),
            structural: StructuralAsks::fromArray($data),
            clarificationQuestions: ListShape::ofStrings(
                $data,
                'clarificationQuestions',
                static fn(string $s): string => $s,
            ),
            humanReviewRequests: ListShape::ofStrings($data, 'humanReviewRequests', static fn(string $s): string => $s),
            negotiation: NestedShape::object($data, 'negotiation', NegotiationAsks::fromArray(...)),
        );
    }
}
