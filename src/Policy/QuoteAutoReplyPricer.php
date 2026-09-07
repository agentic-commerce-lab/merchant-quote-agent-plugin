<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteAutoReplyDetails;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLinePrice;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;

/**
 * Buyers on trunk ask per line ("Requested price"): grant each line exactly
 * its ask, never above the current price. Without per-line asks, scale
 * uniformly by the total discount. When countering an ungrantable ask, scale
 * uniformly by the countered discount ceiling.
 */
final class QuoteAutoReplyPricer
{
    public function price(
        QuoteSnapshot $effective,
        float $discountPercent,
        int $validityDays,
        ?float $counteredRequestPercent = null,
    ): QuoteAutoReplyDetails {
        $hasLineAsks = $this->hasLineAsks($effective->lines);
        $factor = 1 - ($discountPercent / 100);
        $isCounter = $counteredRequestPercent !== null;

        $lineUnitPricesNet = [];
        foreach ($effective->lines as $line) {
            $unitPriceNet = $hasLineAsks && !$isCounter
                ? $this->linePriceFromAsk($line)
                : $this->linePriceFromDiscount($line, $factor);
            $lineUnitPricesNet[] = new QuoteLinePrice(lineItemId: $line->lineItemId(), unitPriceNet: $unitPriceNet);
        }

        return new QuoteAutoReplyDetails(
            discountPercent: $discountPercent,
            perLineAsks: $hasLineAsks,
            lineUnitPricesNet: $lineUnitPricesNet,
            validityDays: $validityDays,
            counteredRequestPercent: $counteredRequestPercent,
        );
    }

    /** @param list<QuoteLineSnapshot> $lines */
    private function hasLineAsks(array $lines): bool
    {
        foreach ($lines as $line) {
            if ($line->requestedUnitPrice !== null) {
                return true;
            }
        }

        return false;
    }

    private function linePriceFromAsk(QuoteLineSnapshot $line): float
    {
        return MoneyMath::roundMoney(min($line->requestedUnitPrice ?? $line->unitPriceNet, $line->unitPriceNet));
    }

    private function linePriceFromDiscount(QuoteLineSnapshot $line, float $factor): float
    {
        return MoneyMath::roundMoney($line->unitPriceNet * $factor);
    }
}
