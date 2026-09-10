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

    public function apply(
        QuoteSnapshot $snapshot,
        ?CommentInterpretation $interpretation,
        ?QuoteLimits $limits = null,
    ): QuoteSnapshot {
        if (($interpretation?->price->bestPriceRequested ?? false) && $limits !== null) {
            return $this->applyBestPrice($snapshot, $limits->maxDiscountPercent);
        }

        $merged = $this->commentTargetMerger->merge($snapshot, $interpretation);
        $extra = $interpretation?->price->additionalDiscountPercent;

        return $extra ? $this->applyExtraPercent($merged, $extra) : $merged;
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
