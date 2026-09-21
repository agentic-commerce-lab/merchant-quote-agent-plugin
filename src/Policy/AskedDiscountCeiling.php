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
 *
 * A buyer has four ways to name a number and all four bind the agent: a
 * percentage in the conversation, the storefront's per-line "Requested price"
 * field, a per-unit price typed in the conversation, and — #164 — an absolute
 * price for the WHOLE quote typed in the conversation. The per-unit one used
 * to fall through here — it lands in `structural.lineChanges` rather than on
 * the line, and NegotiationPipeline hands this class the ANCHORED snapshot,
 * which AskMirror's write-back does not reach — so the model was told the
 * merchant's whole band on exactly the asks QuoteDiscountApplier was already
 * pricing at the buyer's figure. Merging below closes that, and reuses the
 * merger so the cap is measured on the same targets the pricer prices
 * against, including its "structured field wins outside a renegotiation"
 * precedence. The quote-level absolute target needs no merge — it names no
 * line — so it is converted to its equivalent percent directly, off the same
 * quoted total the merged snapshot still carries.
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

        // Comment-borne per-unit targets onto the lines first, so the one
        // ask shape that never touches `requestedUnitPrice` is measured like
        // the two that do. Safe in tax terms: AskInterpreter already ran
        // BuyerPriceSpace::toNet() over these, so they are net here, the same
        // space as `unitPriceNet`. A null interpretation merges nothing.
        $merged = (new CommentTargetMerger())->merge($snapshot, $interpretation);

        // The prompt defines additionalDiscountPercent as being asked "on top
        // of any requested prices already entered", so all three add rather
        // than compete. Summing can only raise the ceiling, never lower it
        // below what the buyer asked for.
        $asked =
            self::fromRequestedLinePrices($merged)
            + ($interpretation?->price->additionalDiscountPercent ?? 0.0)
            + self::targetTotalPercent($snapshot, $interpretation?->price->targetTotal);

        return $asked > 0.0 ? $asked : null;
    }

    /**
     * The buyer's absolute quote-level target (#164), as the discount percent
     * it implies. Measured on the ORIGINAL quoted total, not a merged one —
     * a quote-level figure names no line for merge() to apply it to — and
     * capped at the quoted total itself, so a target above it (no discount at
     * all) contributes nothing rather than a negative ask.
     */
    private static function targetTotalPercent(QuoteSnapshot $snapshot, ?float $targetTotal): float
    {
        if ($targetTotal === null || $snapshot->totalNet <= 0.0) {
            return 0.0;
        }

        return (($snapshot->totalNet - min($targetTotal, $snapshot->totalNet)) / $snapshot->totalNet) * 100;
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
