<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Bench;

/**
 * Narrows a decoded-JSON scenario's `lines` value to Scenario's typed shape.
 * Split out of {@see ScenarioFields} so neither class carries the other's
 * complexity budget.
 *
 * @mago-expect lint:cyclomatic-complexity
 * Three real validation branches (`productRef`, `quantity`,
 * `requestedUnitPrice`), each a distinct way a scenario file can be wrong
 * and each needing its own named exception message. Splitting further would
 * move branches into another file rather than remove them.
 */
final class ScenarioLines
{
    private function __construct() {}

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array{productRef: string, quantity: int, requestedUnitPrice: ?float}>
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

    /** @return array{productRef: string, quantity: int, requestedUnitPrice: ?float} */
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

        return [
            'productRef' => $productRef,
            'quantity' => $quantity,
            'requestedUnitPrice' => self::requestedUnitPrice($fields),
        ];
    }

    /**
     * Optional: a per-line ask the buyer typed into the storefront's own
     * price field, no comment attached. Absent stays null rather than
     * defaulting to a number -- a scenario without one must not accidentally
     * exercise this path.
     *
     * No Net/Gross suffix: BenchNegotiation sends this figure unconverted as
     * `requested_unit_price`, and SwagCommercial's quote line-item route
     * reads that field as GROSS but stores it as NET. So a value written
     * here reappears on the quote's `requestedUnitPrice` divided by the
     * line's own tax factor (its `netRatio`), not equal to what was sent --
     * the bench does not control the unit and must not claim one.
     */
    private static function requestedUnitPrice(array $fields): ?float
    {
        $value = $fields['requestedUnitPrice'] ?? null;

        if ($value === null) {
            return null;
        }

        if (!\is_int($value) && !\is_float($value)) {
            throw new \InvalidArgumentException(
                'Scenario field "lines[].requestedUnitPrice" must be a number when present.',
            );
        }

        return (float) $value;
    }
}
