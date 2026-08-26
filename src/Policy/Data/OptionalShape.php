<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

/**
 * Same boundary-narrowing as RequiredShape, for fields absent-or-null means
 * "not set" (JSON has no key/value distinction worth carrying here).
 */
final class OptionalShape
{
    /** @throws \TypeError */
    public static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return $value === null ? null : RequiredShape::string($data, $key);
    }

    /** @throws \TypeError */
    public static function bool(array $data, string $key): ?bool
    {
        $value = $data[$key] ?? null;

        return $value === null ? null : RequiredShape::bool($data, $key);
    }

    /** @throws \TypeError */
    public static function float(array $data, string $key): ?float
    {
        $value = $data[$key] ?? null;

        return $value === null ? null : RequiredShape::float($data, $key);
    }

    /** @throws \TypeError */
    public static function int(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return $value === null ? null : RequiredShape::int($data, $key);
    }

    /** @throws \TypeError */
    public static function array(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;

        return $value === null ? null : NestedShape::array($data, $key);
    }
}
