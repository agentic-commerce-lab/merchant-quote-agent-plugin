<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Bench;

/**
 * Narrows a decoded-JSON scenario's `lines` value to Scenario's typed shape.
 * Split out of {@see ScenarioFields} so neither class carries the other's
 * complexity budget.
 */
final class ScenarioLines
{
    private function __construct() {}

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array{productRef: string, quantity: int}>
     */
    public static function from(array $data, string $key): array
    {
        $lines = $data[$key] ?? null;
        if (!\is_array($lines) || $lines === []) {
            throw new \InvalidArgumentException(\sprintf(
                'Scenario field "%s" is required and must be a non-empty list.',
                $key,
            ));
        }

        return array_map(self::line(...), $lines);
    }

    /** @return array{productRef: string, quantity: int} */
    private static function line(mixed $line): array
    {
        $fields = \is_array($line) ? $line : [];
        $productRef = $fields['productRef'] ?? null;
        $quantity = $fields['quantity'] ?? null;

        if (!\is_string($productRef) || $productRef === '') {
            throw new \InvalidArgumentException(
                'Scenario field "lines[].productRef" is required and must be a non-empty string.',
            );
        }

        if (!\is_int($quantity)) {
            throw new \InvalidArgumentException(
                'Scenario field "lines[].quantity" is required and must be an integer.',
            );
        }

        return ['productRef' => $productRef, 'quantity' => $quantity];
    }
}
