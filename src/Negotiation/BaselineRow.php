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
    /**
     * Null for anything that does not parse. The caller turns one null row
     * into a null baseline: a partial list would be a smaller cap than the
     * merchant set, applied silently.
     *
     * is_numeric rather than is_float because custom fields survive the
     * database as JSON, so 100.0 can come back as the integer 100.
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

    /** @return array<string, mixed> */
    public static function write(BridgeLine $line): array
    {
        return [
            'lineItemId' => $line->identity->lineItemId,
            'unitPriceNet' => $line->unitPriceNet,
            'quantity' => $line->quantity,
        ];
    }
}
