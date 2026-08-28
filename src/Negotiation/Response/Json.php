<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation\Response;

use MerchantQuoteAgentPlugin\Negotiation\ModelUnavailable;

/** Decoding a model's answer, where every failure means "escalate". */
final class Json
{
    private function __construct() {}

    /**
     * @return array<string, mixed>
     *
     * @throws ModelUnavailable
     */
    public static function object(string $json): array
    {
        try {
            // Decoded twice: PHP's associative array has no object/list
            // distinction left once `{}` and `[]` both become `[]`, so a
            // second, non-associative decode is what tells them apart. The
            // associative one still does the real, recursive decoding.
            $shape = json_decode($json, associative: false, flags: JSON_THROW_ON_ERROR);
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ModelUnavailable('The model did not return JSON.', previous: $e);
        }

        if (!$shape instanceof \stdClass) {
            throw new ModelUnavailable('The model returned JSON that is not an object.');
        }

        return WireArray::orEmpty($decoded);
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(array $raw, string $key): array
    {
        $rows = $raw[$key] ?? null;

        if (!\is_array($rows)) {
            return [];
        }

        $out = [];

        foreach ($rows as $row) {
            if (\is_array($row)) {
                $out[] = WireArray::orEmpty($row);
            }
        }

        return $out;
    }
}
