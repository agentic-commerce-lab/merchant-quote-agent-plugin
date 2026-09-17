<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Bench;

/**
 * Narrows a decoded-JSON scenario array's scalar `mixed` values to Scenario's
 * typed shape, throwing `\InvalidArgumentException` naming the offending
 * field — the message is read by whoever wrote the JSON. Line-item shaping
 * lives in {@see ScenarioLines}, kept apart so this class stays simple.
 */
final class ScenarioFields
{
    private function __construct() {}

    /** @param array<string, mixed> $data */
    public static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!\is_string($value) || $value === '') {
            throw new \InvalidArgumentException(\sprintf(
                'Scenario field "%s" is required and must be a non-empty string.',
                $key,
            ));
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    public static function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return $value === null ? null : self::string($data, $key);
    }

    /**
     * `maxRounds` has no default: nothing else bounds the negotiation loop,
     * so an omitted or zero cap would let a non-converging run go forever.
     *
     * @param array<string, mixed> $data
     */
    public static function maxRounds(array $data): int
    {
        $value = $data['maxRounds'] ?? null;
        if (!\is_int($value) || $value < 1) {
            throw new \InvalidArgumentException('Scenario field "maxRounds" is required and must be an integer >= 1.');
        }

        return $value;
    }
}
