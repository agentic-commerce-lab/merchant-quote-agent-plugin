<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use CuyZ\Valinor\Mapper\MappingError;
use MerchantQuoteAgentPlugin\Policy\Data\ArrayMapper;
use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationAsks;
use MerchantQuoteAgentPlugin\Policy\Data\PriceAsk;
use MerchantQuoteAgentPlugin\Policy\Data\StructuralAsks;

/**
 * The per-field guards behind InterpretationPayload::from(), split out to
 * keep that class's complexity budget for of() and its docblock. Every
 * top-level key CommentInterpretation ever writes must be present, or the
 * payload is treated as an unrecognised shape (see from()'s docblock: a
 * payload written before a field existed must refuse, not half-hydrate).
 *
 * Leaf value objects (PriceAsk, StructuralAsks and everything nested under
 * it, NegotiationAsks and its sub-asks) are rebuilt via ArrayMapper, the
 * same Valinor-backed reflection mapper CommentInterpretation::fromArray()
 * already uses for the same job against the (differently-shaped) LLM
 * extraction payload.
 */
final class InterpretationHydrator
{
    private const TOP_LEVEL_KEYS = [
        'price',
        'structural',
        'clarificationQuestions',
        'humanReviewRequests',
        'negotiation',
    ];

    private function __construct() {}

    /** @param array<string, mixed> $payload */
    public static function hydrate(array $payload): ?CommentInterpretation
    {
        foreach (self::TOP_LEVEL_KEYS as $key) {
            if (!\array_key_exists($key, $payload)) {
                return null;
            }
        }

        try {
            return new CommentInterpretation(
                price: ArrayMapper::mapObject(PriceAsk::class, self::asArray($payload['price'])),
                structural: ArrayMapper::mapObject(StructuralAsks::class, self::asArray($payload['structural'])),
                clarificationQuestions: self::stringList($payload['clarificationQuestions']),
                humanReviewRequests: self::stringList($payload['humanReviewRequests']),
                negotiation: self::negotiation($payload['negotiation']),
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /** @throws \TypeError|MappingError */
    private static function negotiation(mixed $value): ?NegotiationAsks
    {
        if ($value === null) {
            return null;
        }

        return ArrayMapper::mapObject(NegotiationAsks::class, self::asArray($value));
    }

    /** @throws \TypeError */
    private static function asArray(mixed $value): array
    {
        if (!\is_array($value)) {
            throw new \TypeError('Expected an array.');
        }

        return $value;
    }

    /**
     * @throws \TypeError
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        $list = self::asArray($value);

        foreach ($list as $item) {
            if (!\is_string($item)) {
                throw new \TypeError('Expected a list of strings.');
            }
        }

        /** @var list<string> $list */
        return array_values($list);
    }
}
