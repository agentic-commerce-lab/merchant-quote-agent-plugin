<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Config;

/**
 * A field left blank silently escalates (undefined means "ask a human"), but
 * a field that is *set* to something the wrong shape must not: a stored
 * string where a number was expected would otherwise coerce to null and
 * quietly drop whatever guard that number was enforcing (issue: a string
 * `maxQuoteValueNet` disabling the quote value ceiling with no complaint).
 * So absent stays null; present-but-wrong-shaped throws.
 *
 * Split out of RawConfigValue so its own decision points don't add to that
 * class's complexity budget.
 *
 * Not Policy\Data\OptionalShape::int/float, which behaves identically: the
 * \TypeError message here names the type the merchant actually supplied
 * (`maxQuoteValueNet: expected a number, got string.`) and travels verbatim
 * into the merchant's error log, where OptionalShape's `Expected
 * "maxQuoteValueNet" to be numeric.` would not say what they typed.
 */
final class RawValueGuard
{
    private function __construct() {}

    /** @throws \TypeError */
    public static function int(mixed $value, string $key): ?int
    {
        if ($value === null) {
            return null;
        }

        if (!\is_int($value)) {
            throw new \TypeError(\sprintf('%s: expected a whole number, got %s.', $key, get_debug_type($value)));
        }

        return $value;
    }

    /** @throws \TypeError */
    public static function float(mixed $value, string $key): ?float
    {
        if ($value === null) {
            return null;
        }

        if (!\is_int($value) && !\is_float($value)) {
            throw new \TypeError(\sprintf('%s: expected a number, got %s.', $key, get_debug_type($value)));
        }

        return (float) $value;
    }

    /**
     * Blank still means unset — a merchant clearing the field is not the same
     * mistake as typing `978` where `EUR` belongs.
     *
     * @throws \TypeError
     */
    public static function string(mixed $value, string $key): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!\is_string($value)) {
            throw new \TypeError(\sprintf('%s: expected a string, got %s.', $key, get_debug_type($value)));
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
