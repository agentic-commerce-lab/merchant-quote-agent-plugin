<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation\Response;

/**
 * Narrowing a raw decoded-JSON value to the scalar type a DTO field expects,
 * or null if the model sent something else. Wrong-shaped scalars are treated
 * like absent ones here; NegotiateResponse's `action` gate and
 * CommentInterpretation's Valinor mapping are what actually reject a response
 * for being unusable.
 */
final class Scalar
{
    private function __construct() {}

    /** @param array<string, mixed> $raw */
    public static function string(array $raw, string $key): ?string
    {
        $value = $raw[$key] ?? null;

        return \is_string($value) ? $value : null;
    }

    /** @param array<string, mixed> $raw */
    public static function float(array $raw, string $key): ?float
    {
        $value = $raw[$key] ?? null;

        return \is_int($value) || \is_float($value) ? (float) $value : null;
    }

    /** @param array<string, mixed> $raw */
    public static function int(array $raw, string $key): ?int
    {
        $value = $raw[$key] ?? null;

        return \is_int($value) ? $value : null;
    }

    /** @param array<string, mixed> $raw */
    public static function bool(array $raw, string $key): ?bool
    {
        $value = $raw[$key] ?? null;

        return \is_bool($value) ? $value : null;
    }
}
