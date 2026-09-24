<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot as PolicySnapshot;
use MerchantQuoteAgentPlugin\Policy\MarginFloors;

/**
 * The effective minimum-margin floors for one write (spec 2026-09-24). Reads
 * the purchase prices only when the merchant configured a margin, so a shop
 * without one pays no query and behaves exactly as before.
 */
final readonly class MarginFloorGuard
{
    public function __construct(
        private PurchasePricesInterface $purchasePrices,
    ) {}

    /** @return array<string, float> lineItemId => effective floor net; empty when no margin is set */
    public function floors(PolicySnapshot $live, QuoteLimits $limits): array
    {
        $margin = $limits->minMarginPercent;

        if ($margin === null) {
            return [];
        }

        $productIds = [];
        foreach ($live->lines as $line) {
            if ($line->identity->productId !== null) {
                $productIds[] = $line->identity->productId;
            }
        }

        return MarginFloors::of($live, $this->purchasePrices->netUnitPrices($productIds, $live->currencyIso), $margin);
    }
}
