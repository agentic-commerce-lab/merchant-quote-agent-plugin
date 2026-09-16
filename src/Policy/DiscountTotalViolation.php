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
 * the retired TS agent (policy/offer-verification.ts).
 */
final class DiscountTotalViolation
{
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

        // #56, the same one-sidedness at the verify site. A final total ABOVE
        // the reference means the write moved the quote the wrong way.
        //
        // It has one false-positive mode, kept deliberately: a buyer who
        // RAISES a quantity mid-negotiation raises the final total against a
        // baseline that did not move, and that now escalates. It is the
        // mirror of the quantity-REDUCTION limitation #49 documented and
        // fails the same safe way — a human gets it, nothing is under-charged.
        if ($totalDiscount < -Epsilon::MONEY) {
            return sprintf('total discount %s%% raises the quote above its reference total', number_format(
                $totalDiscount,
                decimals: 1,
                thousands_separator: '',
            ));
        }

        return $totalDiscount > ($limits->maxDiscountPercent + Epsilon::MONEY)
            ? sprintf(
                'total discount %s%% exceeds the %s%% limit',
                number_format($totalDiscount, decimals: 1, thousands_separator: ''),
                $limits->maxDiscountPercent,
            )
            : null;
    }
}
