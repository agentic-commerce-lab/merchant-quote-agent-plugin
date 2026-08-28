<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Config;

/**
 * The one place `??` happens when reading Task 5's flat raw-config array, so
 * every other helper in this namespace narrows a value it already has rather
 * than repeating the lookup.
 */
final class RawValue
{
    private function __construct() {}

    /** @param array<string, mixed> $raw */
    public static function at(array $raw, string $key): mixed
    {
        return $raw[$key] ?? null;
    }
}
