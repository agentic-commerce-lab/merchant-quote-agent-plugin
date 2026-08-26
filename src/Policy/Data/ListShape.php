<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

final class ListShape
{
    /**
     * @template T
     * @param callable(array): T $mapper
     * @return list<T>
     * @throws \TypeError
     */
    public static function of(array $data, string $key, callable $mapper): array
    {
        return self::map($data, $key, static fn(mixed $item): array => self::asArray($item), $mapper);
    }

    /**
     * @template T
     * @param callable(string): T $mapper
     * @return list<T>
     * @throws \TypeError
     */
    public static function ofStrings(array $data, string $key, callable $mapper): array
    {
        return self::map($data, $key, static fn(mixed $item): string => self::asString($item), $mapper);
    }

    /**
     * @template U
     * @template T
     * @param callable(mixed): U $narrow
     * @param callable(U): T $mapper
     * @return list<T>
     * @throws \TypeError
     */
    private static function map(array $data, string $key, callable $narrow, callable $mapper): array
    {
        $items = self::asList($data, $key);
        $result = [];
        foreach ($items as $item) {
            $result[] = $mapper($narrow($item));
        }

        return $result;
    }

    /** @throws \TypeError */
    private static function asList(array $data, string $key): array
    {
        $items = $data[$key] ?? [];
        if (!is_array($items)) {
            throw new \TypeError(sprintf('Expected "%s" to be a list.', $key));
        }

        return array_values($items);
    }

    /** @throws \TypeError */
    private static function asArray(mixed $item): array
    {
        if (!is_array($item)) {
            throw new \TypeError('Expected a list item to be an array.');
        }

        return $item;
    }

    /** @throws \TypeError */
    private static function asString(mixed $item): string
    {
        if (!is_string($item)) {
            throw new \TypeError('Expected a list item to be a string.');
        }

        return $item;
    }
}
