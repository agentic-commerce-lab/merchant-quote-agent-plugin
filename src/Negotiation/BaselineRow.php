<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot as BridgeLine;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineIdentity as PolicyLineIdentity;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot as PolicyLine;

/**
 * One stored baseline row, in each direction. Split out of QuoteBaseline to
 * keep that class within the complexity gate, the same way
 * LineReferenceViolation was split out of LinePriceOfferCheck.
 */
final class BaselineRow
{
    private function __construct() {}

    /**
     * Null for anything that does not parse. The caller turns one null row
     * into a null baseline: a partial list would be a smaller cap than the
     * merchant set, applied silently.
     *
     * `unitPriceNet` uses is_numeric rather than is_int because custom fields
     * survive the database as JSON, so 100.0 can come back as the integer
     * 100. `quantity` uses strict is_int instead: it originates as a PHP int
     * (Shopware line quantities are never fractional) and round-trips as one,
     * so a non-int value here means the field is corrupt, not that JSON
     * reshaped it.
     */
    public static function read(mixed $row): ?PolicyLine
    {
        if (!\is_array($row)) {
            return null;
        }

        $id = $row['lineItemId'] ?? null;
        $unitPriceNet = $row['unitPriceNet'] ?? null;
        $quantity = $row['quantity'] ?? null;

        if (!\is_string($id) || !\is_numeric($unitPriceNet) || !\is_int($quantity)) {
            return null;
        }

        return new PolicyLine(
            identity: new PolicyLineIdentity(lineItemId: $id),
            quantity: $quantity,
            unitPriceNet: (float) $unitPriceNet,
        );
    }

    /**
     * Every row in $rows, in order, or null if any one fails to parse. Split
     * out of QuoteBaseline::read() to keep that class within the complexity
     * gate, the same reason this class exists at all.
     *
     * A partial list is a smaller cap than the merchant set, applied
     * silently, so one bad row must fail the whole baseline, not just itself.
     *
     * `$rows` is `array<array-key, mixed>` rather than `list<mixed>`: it comes
     * from a custom field, so nothing upstream guarantees sequential keys.
     *
     * @param array<array-key, mixed> $rows
     *
     * @return list<PolicyLine>|null
     */
    public static function readAll(array $rows): ?array
    {
        $lines = [];

        foreach ($rows as $row) {
            $line = self::read($row);

            if ($line === null) {
                return null;
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /** @return array<string, mixed> */
    public static function write(BridgeLine $line): array
    {
        return self::row($line->identity->lineItemId, $line->unitPriceNet, $line->quantity);
    }

    /**
     * A row the baseline already holds, re-serialised (#54). An extension
     * rewrites the whole list, so the stored rows travel back out through the
     * same field names they came in by.
     *
     * @return array<string, mixed>
     */
    public static function writeStored(PolicyLine $line): array
    {
        return self::row($line->lineItemId(), $line->unitPriceNet, $line->quantity);
    }

    /** @return array<string, mixed> */
    private static function row(string $lineItemId, float $unitPriceNet, int $quantity): array
    {
        return [
            'lineItemId' => $lineItemId,
            'unitPriceNet' => $unitPriceNet,
            'quantity' => $quantity,
        ];
    }
}
