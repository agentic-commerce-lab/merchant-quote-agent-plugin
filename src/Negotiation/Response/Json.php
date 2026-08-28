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
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ModelUnavailable('The model did not return JSON.', previous: $e);
        }

        if (!\is_array($decoded) || array_is_list($decoded)) {
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
