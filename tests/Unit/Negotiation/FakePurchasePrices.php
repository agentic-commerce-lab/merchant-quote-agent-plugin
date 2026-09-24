<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\PurchasePricesInterface;

final class FakePurchasePrices implements PurchasePricesInterface
{
    /** @var list<array{list<string>, string}> */
    public array $calls = [];

    /** @param array<string, float> $prices productId => net purchase price */
    public function __construct(
        private readonly array $prices = [],
    ) {}

    #[\Override]
    public function netUnitPrices(array $productIds, string $currencyIso): array
    {
        $this->calls[] = [$productIds, $currencyIso];

        return array_intersect_key($this->prices, array_flip($productIds));
    }
}
