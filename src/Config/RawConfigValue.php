<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Config;

/**
 * Type-narrows one value out of a flat `array<string, mixed>` of raw config.
 * A wrong-shaped value coerces to null rather than throwing: the raw array
 * comes from Task 5's reader, which cannot itself be validated, so this is
 * the layer that turns "not what we expected" into "not set" rather than a
 * hard crash.
 */
final class RawConfigValue
{
    private const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    private function __construct() {}

    /** @param array<string, mixed> $raw */
    public static function baseUrl(array $raw): string
    {
        $url = trim(self::stringOrEmpty($raw, 'llmBaseUrl'));

        return $url === '' ? self::DEFAULT_BASE_URL : $url;
    }

    /** @param array<string, mixed> $raw */
    public static function bool(array $raw, string $key): ?bool
    {
        $value = RawValue::at($raw, $key);

        return \is_bool($value) ? $value : null;
    }

    /** @param array<string, mixed> $raw */
    public static function int(array $raw, string $key): ?int
    {
        $value = RawValue::at($raw, $key);

        return \is_int($value) ? $value : null;
    }

    /** @param array<string, mixed> $raw */
    public static function string(array $raw, string $key): ?string
    {
        $value = RawValue::at($raw, $key);

        return \is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * An unset or blank field read as `''` rather than null, for callers
     * that always need a string.
     *
     * @param array<string, mixed> $raw
     */
    public static function stringOrEmpty(array $raw, string $key): string
    {
        return self::string($raw, $key) ?? '';
    }

    /** @param array<string, mixed> $raw */
    public static function float(array $raw, string $key): ?float
    {
        $value = RawValue::at($raw, $key);

        return \is_int($value) || \is_float($value) ? (float) $value : null;
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return list<string>|null
     */
    public static function stringList(array $raw, string $key): ?array
    {
        $values = RawValue::at($raw, $key);

        if (!\is_array($values)) {
            return null;
        }

        $strings = array_values(array_filter($values, static fn(mixed $value): bool => \is_string($value)));

        return $strings === [] ? null : $strings;
    }
}
