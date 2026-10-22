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

    /**
     * The inverse of of(), and the one reason it exists: the nightly
     * improvement replay reads a decision's stored interpretation back, because
     * the buyer's raw message is deliberately never persisted and so cannot be
     * re-extracted.
     *
     * Hand-written, because json_decode cannot rebuild typed value objects.
     * That makes it the one place that CAN drift from of(), which is why
     * InterpretationPayloadTest round-trips a fully-populated interpretation:
     * a field added to CommentInterpretation and not to this mapper fails
     * there rather than silently replaying an ask that is missing half of
     * itself.
     *
     * Returns null rather than throwing, so a row written before a field
     * existed counts as `skipped` on the run instead of failing the night.
     *
     * @param array<string, mixed> $payload
     */
    public static function from(array $payload): ?CommentInterpretation
    {
        return InterpretationHydrator::hydrate($payload);
    }
}
