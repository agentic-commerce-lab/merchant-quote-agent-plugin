<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\LinePriceNormalizer;
use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLinePrice;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot;
use PHPUnit\Framework\TestCase;

final class LinePriceNormalizerTest extends TestCase
{
    public function testItNormalizesSlugToRealLineItemId(): void
    {
        $lines = [
            new QuoteLineSnapshot(
                lineItemId: 'uuid-1234',
                label: 'FusionGlow Sport',
                quantity: 10,
                listUnitPriceNet: 799.99,
                baselineUnitPriceNet: 799.99,
            ),
        ];

        $offer = new ProposedOffer(
            orderTotalNet: 7600.0,
            price: new OfferedPrice(discountPercent: null, linePricesNet: [new QuoteLinePrice(
                'fusionglow_sport',
                760.0,
            )]),
            delivery: null,
            payment: null,
        );

        $normalized = LinePriceNormalizer::normalize($offer, $lines);

        self::assertEquals([new QuoteLinePrice('uuid-1234', 760.0)], $normalized->price->linePricesNet);
    }

    public function testItFallsBackToSingleLineIdWhenMismatch(): void
    {
        $lines = [
            new QuoteLineSnapshot(
                lineItemId: 'uuid-5678',
                label: 'Test Product',
                quantity: 1,
                listUnitPriceNet: 100.0,
                baselineUnitPriceNet: 100.0,
            ),
        ];

        $offer = new ProposedOffer(
            orderTotalNet: 90.0,
            price: new OfferedPrice(discountPercent: null, linePricesNet: [new QuoteLinePrice('unknown-slug', 90.0)]),
            delivery: null,
            payment: null,
        );

        $normalized = LinePriceNormalizer::normalize($offer, $lines);

        self::assertEquals([new QuoteLinePrice('uuid-5678', 90.0)], $normalized->price->linePricesNet);
    }
}
