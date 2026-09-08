<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Store;

/**
 * The one tolerant JSON decode shared by the three table stores: a row that
 * does not decode to an array reads as an empty one, which every caller's
 * `fromArray()` then rejects on its own required fields — one unreadable row
 * must not fail a whole session's records request.
 */
final class RowDecoder
{
    private function __construct() {}

    /** @return array<array-key, mixed> */
    public static function decode(string $json): array
    {
        $decoded = json_decode($json, associative: true);

        return \is_array($decoded) ? $decoded : [];
    }
}
