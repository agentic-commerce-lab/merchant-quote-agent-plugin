<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;

/**
 * Applies a buyer's free-text extra-discount / best-price / renegotiation
 * asks onto a quote snapshot, producing the "effective" snapshot QuoteDecider
 * then checks against the merchant's limits.
 *
 * Ported from `applyExtraDiscount` in the retired TS agent (policy/quote-decision.ts).
 */
final class QuoteDiscountApplier
{
    private readonly CommentTargetMerger $commentTargetMerger;

    public function __construct(?CommentTargetMerger $commentTargetMerger = null)
    {
        $this->commentTargetMerger = $commentTargetMerger ?? new CommentTargetMerger();
    }

    /**
     * A budget named for the whole quote IS a quote-level target, so it lands
     * on the one field the band decider and the uniform pricer already read:
     * with no per-line asks on the snapshot, QuoteAutoReplyPricer scales every
     * line by the resulting percentage, and nothing else has to know.
     *
     * The clamp itself is QuoteSnapshot::cappedAtBudget(), which states why it
     * takes the deeper of the two asks.
     */
    public function apply(
        QuoteSnapshot $snapshot,
        ?CommentInterpretation $interpretation,
        ?QuoteLimits $limits = null,
    ): QuoteSnapshot {
        if (($interpretation?->price->bestPriceRequested ?? false) && $limits !== null) {
            return $this->applyBestPrice($snapshot, $limits->maxDiscountPercent);
        }

        $merged = $this->commentTargetMerger->merge($snapshot, $interpretation);

        // The absolute quote-level target from #164, honoured the same place
        // a per-line comment target is: before additionalDiscountPercent
        // stacks on top of it, exactly as the prompt states that field is "on
        // top of any requested prices already entered". An explicit total
        // wins outright over whatever merge() rescaled off the lines, since
        // naming a total is more specific than naming a line.
        $targetTotal = $interpretation?->price->targetTotal;
        if ($targetTotal !== null) {
            $merged = $merged->withBuyerTargetNet(MoneyMath::roundMoney($targetTotal));
        }

        $extra = $interpretation?->price->additionalDiscountPercent;
        $scaled = $extra ? $this->applyExtraPercent($merged, $extra) : $merged;

        return $scaled->cappedAtBudget($interpretation?->price->targetTotal);
    }

    private function applyBestPrice(QuoteSnapshot $snapshot, float $maxDiscountPercent): QuoteSnapshot
    {
        $factor = 1 - ($maxDiscountPercent / 100);
        $lines = [];
        foreach ($snapshot->lines as $line) {
            $lines[] = $line->withRequestedUnitPrice(MoneyMath::roundMoney($line->unitPriceNet * $factor));
        }

        return $snapshot->withBuyerTargetNet(MoneyMath::roundMoney($snapshot->totalNet * $factor))->withLines($lines);
    }

    private function applyExtraPercent(QuoteSnapshot $snapshot, float $extraPercent): QuoteSnapshot
    {
        $factor = 1 - ($extraPercent / 100);
        $lines = [];
        foreach ($snapshot->lines as $line) {
            $lines[] = self::scaleRequestedPrice($line, $factor);
        }

        return $snapshot
            ->withBuyerTargetNet(MoneyMath::roundMoney(($snapshot->buyerTargetNet ?? $snapshot->totalNet) * $factor))
            ->withLines($lines);
    }

    private static function scaleRequestedPrice(QuoteLineSnapshot $line, float $factor): QuoteLineSnapshot
    {
        return $line->requestedUnitPrice === null
            ? $line
            : $line->withRequestedUnitPrice(MoneyMath::roundMoney($line->requestedUnitPrice * $factor));
    }
}
