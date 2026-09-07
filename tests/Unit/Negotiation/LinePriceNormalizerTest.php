<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\LinePriceNormalizer;
use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLinePrice;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot;
use PHPUnit\Framework\TestCase;

final class LinePriceNormalizerTest extends TestCase
{
    public function testItNormalizesSlugToRealLineItemId(): void
    {
        $lines = [self::line('uuid-1234', 'FusionGlow Sport', 10, 799.99)];

        $offer = self::offerFor(7600.0, new QuoteLinePrice('fusionglow_sport', 760.0));

        $normalized = LinePriceNormalizer::normalize($offer, $lines);

        self::assertEquals([new QuoteLinePrice('uuid-1234', 760.0)], $normalized->price->linePricesNet);
    }

    public function testItFallsBackToSingleLineIdWhenMismatch(): void
    {
        $lines = [self::line('uuid-5678', 'Test Product', 1, 100.0)];

        $offer = self::offerFor(90.0, new QuoteLinePrice('unknown-slug', 90.0));

        $normalized = LinePriceNormalizer::normalize($offer, $lines);

        self::assertEquals([new QuoteLinePrice('uuid-5678', 90.0)], $normalized->price->linePricesNet);
    }

    /**
     * With more than one line there is no single line to fall back to, so an
     * unresolvable id has to survive unchanged — LinePriceOfferCheck is what
     * then rejects it as "not on this quote".
     */
    public function testItLeavesAnUnresolvableIdAloneWhenSeveralLinesCouldBeMeant(): void
    {
        $lines = [
            self::line('uuid-1', 'First Product', 1, 100.0),
            self::line('uuid-2', 'Second Product', 1, 200.0),
        ];

        $offer = self::offerFor(280.0, new QuoteLinePrice('unknown-slug', 90.0));

        $normalized = LinePriceNormalizer::normalize($offer, $lines);

        self::assertEquals([new QuoteLinePrice('unknown-slug', 90.0)], $normalized->price->linePricesNet);
    }

    private static function line(string $id, string $label, int $quantity, float $unitPriceNet): QuoteLineSnapshot
    {
        return new QuoteLineSnapshot(
            identity: new QuoteLineIdentity(lineItemId: $id, label: $label),
            quantity: $quantity,
            unitPriceNet: $unitPriceNet,
            totalNet: $unitPriceNet * $quantity,
        );
    }

    private static function offerFor(float $orderTotalNet, QuoteLinePrice $linePrice): ProposedOffer
    {
        return new ProposedOffer(orderTotalNet: $orderTotalNet, price: new OfferedPrice(linePricesNet: [$linePrice]));
    }
}
