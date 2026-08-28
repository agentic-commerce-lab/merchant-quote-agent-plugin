<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation\Response;

/**
 * Renaming wire (snake_case) keys to their DTO (camelCase) names — the
 * rekeying both readers otherwise repeat per row and per nested block.
 * A missing wire key maps to null, matching the "unset means unset" contract.
 */
final class RenamedRows
{
    private function __construct() {}

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $keyMap wire key => target key
     *
     * @return array<string, mixed>
     */
    public static function of(array $row, array $keyMap): array
    {
        $out = [];

        foreach ($keyMap as $wireKey => $targetKey) {
            $out[$targetKey] = $row[$wireKey] ?? null;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, string> $keyMap wire key => target key
     *
     * @return list<array<string, mixed>>
     */
    public static function list(array $raw, string $key, array $keyMap): array
    {
        return array_map(static fn(array $row): array => self::of($row, $keyMap), Json::rows($raw, $key));
    }
}
