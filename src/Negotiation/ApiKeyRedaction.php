<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

/**
 * Takes the model API key back out of provider text before it is traced.
 *
 * The key never goes into a request body, but what comes back is the
 * provider's to write: a gateway that echoes the Authorization header in a
 * 401 would otherwise put the merchant's key into the trace table and every
 * export. Its own class only because ModelCallTrace's complexity budget is
 * spent.
 */
final class ApiKeyRedaction
{
    /** A blank or trivial key would match, and mangle, ordinary content. */
    private const MIN_KEY_BYTES = 8;

    private const MARKER = '[redacted]';

    private function __construct() {}

    /**
     * Every exact occurrence of the key in a string leaf, replaced by
     * `[redacted]`. Cannot throw: it only walks arrays and replaces strings.
     *
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    public static function of(#[\SensitiveParameter] string $apiKey, array $content): array
    {
        return \strlen($apiKey) < self::MIN_KEY_BYTES ? $content : self::walk($content, $apiKey);
    }

    /**
     * ponytail: string leaves only, array keys are not walked. A provider
     * echoes a header as a value, not as a JSON object key; walk keys too if
     * one ever does.
     *
     * @template K of array-key
     *
     * @param array<K, mixed> $value
     *
     * @return array<K, mixed>
     */
    private static function walk(array $value, #[\SensitiveParameter] string $apiKey): array
    {
        foreach ($value as $key => $leaf) {
            $value[$key] = match (true) {
                \is_string($leaf) => str_replace($apiKey, self::MARKER, $leaf),
                \is_array($leaf) => self::walk($leaf, $apiKey),
                default => $leaf,
            };
        }

        return $value;
    }
}
