<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;

/**
 * Applies the per-line target prices a comment asked for onto a snapshot, and
 * rescales the buyer's quote-level target off the result. Which targets stand
 * at all is CommentLineTargets::adoptedBy()'s call.
 *
 * Ported from `mergeCommentTargets` in src/policy/quote-decision.ts.
 */
final class CommentTargetMerger
{
    private readonly CommentLineTargets $lineTargets;

    public function __construct()
    {
        $this->lineTargets = new CommentLineTargets();
    }

    public function merge(QuoteSnapshot $snapshot, ?CommentInterpretation $interpretation): QuoteSnapshot
    {
        $targets = $this->lineTargets->extract($interpretation);
        if ($targets === []) {
            return $snapshot;
        }

        // Guarded on the EXTRACTED targets, not the adopted ones: an ask that
        // changes no line still rescales the buyer's target off the asks that
        // DO stand, which is how a structured ask the comment could not
        // override becomes a quote-level percentage at all. QuoteDeciderTest's
        // "structured field wins" fixture is the one that measures it.
        $adopted = $this->lineTargets->adoptedBy($snapshot, $targets);
        $lines = [];
        foreach ($snapshot->lines as $line) {
            $target = $adopted[$line->lineItemId()] ?? null;
            $lines[] = $target === null ? $line : $line->withRequestedUnitPrice($target);
        }

        return $snapshot->withLines($lines)->withBuyerTargetNet(self::rescaledBuyerTarget($snapshot, $lines));
    }

    /**
     * The targets this merger would actually apply.
     *
     * Public because the mirror that writes a comment target back onto
     * `quote_line_item.requested_price` must display the number the policy
     * layer will PRICE against, not the raw extraction: on a line where a
     * stale structured ask wins, the comment's target is never priced, and a
     * displayed number the agent ignored is worse than no number at all.
     *
     * @return array<string, float> line item id => adopted target unit price, net
     */
    public function adopted(QuoteSnapshot $snapshot, ?CommentInterpretation $interpretation): array
    {
        return $this->lineTargets->adoptedBy($snapshot, $this->lineTargets->extract($interpretation));
    }

    /** @param list<QuoteLineSnapshot> $lines */
    private static function rescaledBuyerTarget(QuoteSnapshot $snapshot, array $lines): ?float
    {
        $current = 0.0;
        $requested = 0.0;
        foreach ($lines as $line) {
            $current += $line->unitPriceNet * $line->quantity;
            $requested += ($line->requestedUnitPrice ?? $line->unitPriceNet) * $line->quantity;
        }

        return $current > 0
            ? MoneyMath::roundMoney($snapshot->totalNet * ($requested / $current))
            : $snapshot->buyerTargetNet;
    }
}
