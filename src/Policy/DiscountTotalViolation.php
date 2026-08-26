<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;

/**
 * Attribute the delivery-authorized waiver to delivery, not price: measure
 * the price discount against a final total with the waiver added back.
 *
 * Ported from the total-discount half of `verifyTotals` in
 * src/policy/offer-verification.ts.
 */
final class DiscountTotalViolation
{
    private const float EPSILON = 0.01;

    public static function check(
        QuoteSnapshot $reference,
        QuoteSnapshot $final,
        QuoteLimits $limits,
        float $allowedExtraDiscountNet,
    ): ?string {
        if ($reference->totalNet <= 0) {
            return null;
        }

        $priceFinalNet = $final->totalNet + $allowedExtraDiscountNet;
        $totalDiscount = (($reference->totalNet - $priceFinalNet) / $reference->totalNet) * 100;

        return $totalDiscount > ($limits->maxDiscountPercent + self::EPSILON)
            ? sprintf(
                'total discount %s%% exceeds the %s%% limit',
                number_format($totalDiscount, decimals: 1, thousands_separator: ''),
                $limits->maxDiscountPercent,
            )
            : null;
    }
}
