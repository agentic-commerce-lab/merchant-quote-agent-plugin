<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

/**
 * The merchant's own cost per product (spec 2026-09-24). Internal only: what
 * this returns feeds the minimum-margin floor and must never reach a model call
 * or a buyer comment.
 */
interface PurchasePricesInterface
{
    /**
     * @param list<string> $productIds ids that are not product ids are ignored
     *
     * @return array<string, float> productId => net purchase price per unit in
     *     $currencyIso; products without one are left out
     */
    public function netUnitPrices(array $productIds, string $currencyIso): array;
}
