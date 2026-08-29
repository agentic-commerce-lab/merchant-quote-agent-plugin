<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;

/**
 * CommentInterpretation as a JSON-shaped array. json_decode(json_encode())
 * rather than a hand-written mapper: the interpretation is a tree of readonly
 * value objects with public properties, and a mapper here would silently drift
 * from it every time #18 adds a field.
 */
final class InterpretationPayload
{
    private function __construct() {}

    /** @return array<string, mixed> */
    public static function of(CommentInterpretation $interpretation): array
    {
        $encoded = json_encode($interpretation, JSON_THROW_ON_ERROR);
        $decoded = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);

        if (!\is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
