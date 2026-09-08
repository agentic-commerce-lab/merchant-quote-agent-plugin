<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;

/**
 * How much discount the buyer actually asked for, as a percentage of the
 * quoted total — the ceiling the agent may not exceed.
 *
 * Quotes 1017 and 1018 both gave away margin nobody requested: 2.70% asked
 * against 5% granted, and 3.41% asked against 5% granted. Every offer check
 * passed, because they all bound against `maxDiscountPercent` and nothing
 * compared the offer with the ask.
 *
 * Deliberately NOT read from `QuoteSnapshot::$buyerTargetNet`, which looks like
 * the right field and is not: `SnapshotAdapter::toPolicy()` never populates it,
 * and only CommentTargetMerger does — so it is null for exactly the
 * structured-ask quotes this exists for.
 */
final class AskedDiscountCeiling
{
    private function __construct() {}

    /**
     * Null means the buyer named no number, so the merchant's own cap stands.
     *
     * A best-price ask returns null on purpose: "your best price" IS a request
     * for the maximum, so there is no stated figure to hold the agent to and
     * the merchant's policy answers it — which is what the extract prompt
     * already promises for that field.
     */
    public static function percent(QuoteSnapshot $snapshot, ?CommentInterpretation $interpretation): ?float
    {
        if ($interpretation?->price->bestPriceRequested === true) {
            return null;
        }

        // The prompt defines additionalDiscountPercent as being asked "on top
        // of any requested prices already entered", so the two asks add rather
        // than compete. Summing can only raise the ceiling, never lower it
        // below what the buyer asked for.
        $asked = self::fromRequestedLinePrices($snapshot) + ($interpretation?->price->additionalDiscountPercent ?? 0.0);

        return $asked > 0.0 ? $asked : null;
    }

    /**
     * `min(requested, quoted)` per line, matching QuoteAutoReplyPricer: a
     * `requested_price` above the quoted one is not an ask for a markup, and a
     * line that is already at or below its target contributes nothing.
     */
    private static function fromRequestedLinePrices(QuoteSnapshot $snapshot): float
    {
        $quoted = 0.0;
        $asked = 0.0;

        foreach ($snapshot->lines as $line) {
            $quoted += $line->unitPriceNet * $line->quantity;
            $asked += min($line->requestedUnitPrice ?? $line->unitPriceNet, $line->unitPriceNet) * $line->quantity;
        }

        return $quoted > 0.0 ? (($quoted - $asked) / $quoted) * 100 : 0.0;
    }
}
