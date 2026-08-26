<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;

/**
 * Per-line target prices asked in comments behave exactly like the structured
 * "Requested price" field; the structured field wins unless this is a
 * renegotiation round (`change_requested`), where the newer comment ask wins.
 *
 * Ported from `mergeCommentTargets` in src/policy/quote-decision.ts.
 */
final class CommentTargetMerger
{
    private readonly CommentLineTargets $lineTargets;

    public function __construct(?CommentLineTargets $lineTargets = null)
    {
        $this->lineTargets = $lineTargets ?? new CommentLineTargets();
    }

    public function merge(QuoteSnapshot $snapshot, ?CommentInterpretation $interpretation): QuoteSnapshot
    {
        $targets = $this->lineTargets->extract($interpretation);
        if ($targets === []) {
            return $snapshot;
        }

        $commentWins = $snapshot->lifecycle->stateTechnicalName === 'change_requested';
        $lines = [];
        foreach ($snapshot->lines as $line) {
            $lines[] = self::withMergedTarget($line, $targets, $commentWins);
        }

        return $snapshot->withLines($lines)->withBuyerTargetNet(self::rescaledBuyerTarget($snapshot, $lines));
    }

    /** @param array<string, float> $targets */
    private static function withMergedTarget(
        QuoteLineSnapshot $line,
        array $targets,
        bool $commentWins,
    ): QuoteLineSnapshot {
        $target = $targets[$line->lineItemId()] ?? null;
        $staleStructuredAskWins = $line->requestedUnitPrice !== null && !$commentWins;

        return $target === null || $staleStructuredAskWins ? $line : $line->withRequestedUnitPrice($target);
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
