<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

final class NestedShape
{
    /** @throws \TypeError */
    public static function array(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            throw new \TypeError(sprintf('Expected "%s" to be an array.', $key));
        }

        return $value;
    }

    /**
     * @template T
     * @param callable(array): T $fromArray
     * @return T|null
     * @throws \TypeError
     */
    public static function object(array $data, string $key, callable $fromArray): mixed
    {
        $nested = OptionalShape::array($data, $key);

        return $nested === null ? null : $fromArray($nested);
    }
}
