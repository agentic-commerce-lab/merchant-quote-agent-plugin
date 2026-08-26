<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

/**
 * Narrows a decoded-JSON array's `mixed` values to concrete types at the
 * fixture/wire boundary. `fromArray()` methods are the one place in this
 * layer that see untyped data; everywhere past them is fully typed.
 */
final class RequiredShape
{
    /** @throws \TypeError */
    public static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new \TypeError(sprintf('Expected "%s" to be a string.', $key));
        }

        return $value;
    }

    /** @throws \TypeError */
    public static function bool(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;
        if (!is_bool($value)) {
            throw new \TypeError(sprintf('Expected "%s" to be a bool.', $key));
        }

        return $value;
    }

    /** @throws \TypeError */
    public static function float(array $data, string $key): float
    {
        $value = $data[$key] ?? null;
        if (!is_int($value) && !is_float($value)) {
            throw new \TypeError(sprintf('Expected "%s" to be numeric.', $key));
        }

        return (float) $value;
    }

    /** @throws \TypeError */
    public static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value)) {
            throw new \TypeError(sprintf('Expected "%s" to be an int.', $key));
        }

        return $value;
    }
}
