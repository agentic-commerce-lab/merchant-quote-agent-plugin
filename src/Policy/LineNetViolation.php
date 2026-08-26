<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot;

/**
 * One final line checked, in net terms, against its reference line. Split
 * out of LineOfferVerifier to keep per-class cyclomatic complexity within
 * the quality gate.
 *
 * Ported from the per-line body of `verifyLines` in
 * src/policy/offer-verification.ts.
 */
final class LineNetViolation
{
    /** @return list<string> */
    public static function check(
        QuoteLineSnapshot $line,
        QuoteLineSnapshot $referenceLine,
        float $finalToNet,
        float $referenceToNet,
        float $maxLineFactor,
    ): array {
        // Negative lines (e.g. a Shopware-generated quote-discount line) are
        // not priced positions; the totals check covers their effect.
        if ($line->unitPriceNet < 0 || $referenceLine->unitPriceNet < 0) {
            return [];
        }

        $priceNet = $line->unitPriceNet * $finalToNet;
        $referenceNet = $referenceLine->unitPriceNet * $referenceToNet;
        $label = $line->label() ?? $line->lineItemId();

        $violations = [];
        if ($priceNet < (($referenceNet * $maxLineFactor) - Epsilon::MONEY)) {
            $violations[] = sprintf(
                'line "%s" priced %s net below the allowed minimum %s',
                $label,
                number_format($priceNet, decimals: 2, thousands_separator: ''),
                number_format($referenceNet * $maxLineFactor, decimals: 2, thousands_separator: ''),
            );
        }
        if ($priceNet > ($referenceNet + Epsilon::MONEY)) {
            $violations[] = sprintf(
                'line "%s" priced %s net above its reference price %s',
                $label,
                number_format($priceNet, decimals: 2, thousands_separator: ''),
                number_format($referenceNet, decimals: 2, thousands_separator: ''),
            );
        }

        return $violations;
    }
}
