<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation\Response;

/**
 * Narrows a decoded-JSON value that might be an object to a proper
 * array<string, mixed> — json_decode alone leaves array-key/mixed typing too
 * loose for the analyzer, and a value that isn't an array simply has no
 * fields, which is not a failure this deep in optional, nested wire data.
 */
final class WireArray
{
    private function __construct() {}

    /** @return array<string, mixed> */
    public static function orEmpty(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }
}
