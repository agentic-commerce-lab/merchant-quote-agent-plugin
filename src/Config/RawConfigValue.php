<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Config;

/**
 * Type-narrows one value out of a flat `array<string, mixed>` of raw config.
 *
 * Two behaviours, by key. Where a wrong-shaped value would silently drop a
 * merchant guard — the numbers, via RawValueGuard — present-but-wrong-shaped
 * throws. Everywhere else (bool, string, stringList) a wrong-shaped value
 * still coerces to null, because there the worst outcome is a field reading
 * as unset. Absent is always null in both.
 */
final class RawConfigValue
{
    private const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    private function __construct() {}

    /** @param array<string, mixed> $raw */
    public static function baseUrl(array $raw): string
    {
        $url = self::stringOrEmpty($raw, 'llmBaseUrl');

        return $url === '' ? self::DEFAULT_BASE_URL : $url;
    }

    /**
     * Only ever called once `credentialProblems()` has come back empty, so the
     * key is non-blank here by construction.
     *
     * @param array<string, mixed> $raw
     */
    public static function llm(array $raw, #[\SensitiveParameter] string $apiKey): ModelAccess
    {
        return new ModelAccess($apiKey, self::baseUrl($raw), self::stringOrEmpty($raw, 'llmModel'));
    }

    /**
     * The two checks a model call needs: a key to authenticate with, and a
     * model name to send the request to. Split out of the factory to keep its
     * cyclomatic complexity down.
     *
     * @param array<string, mixed> $raw
     *
     * @return list<string>
     */
    public static function credentialProblems(array $raw, #[\SensitiveParameter] string $apiKey): array
    {
        $problems = [];

        if ($apiKey === '') {
            $problems[] = 'No LLM API key is set. The agent cannot interpret a buyer\'s ask without one.';
        }

        if (self::string($raw, 'llmModel') === null) {
            $problems[] = 'No model name is set. Name the model to send requests to, for example gpt-4o-mini.';
        }

        return $problems;
    }

    /** @param array<string, mixed> $raw */
    public static function bool(array $raw, string $key): ?bool
    {
        $value = RawValue::at($raw, $key);

        return \is_bool($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @throws \TypeError see RawValueGuard
     */
    public static function int(array $raw, string $key): ?int
    {
        return RawValueGuard::int(RawValue::at($raw, $key), $key);
    }

    /** @param array<string, mixed> $raw */
    public static function string(array $raw, string $key): ?string
    {
        $value = RawValue::at($raw, $key);

        return \is_string($value) && trim($value) !== '' ? trim($value) : null;
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

    /**
     * @param array<string, mixed> $raw
     *
     * @throws \TypeError see RawValueGuard
     */
    public static function float(array $raw, string $key): ?float
    {
        return RawValueGuard::float(RawValue::at($raw, $key), $key);
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
