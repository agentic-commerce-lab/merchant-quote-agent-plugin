<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Policy\QuoteWidePercent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** How a quote-wide offer's percentage is written on the live prices (design note 2026-09-28). */
final class QuoteWidePercentTest extends TestCase
{
    /**
     * A merchant raised the line from 100 to 120 after the baseline was
     * stamped. Anchored at 100, a hold wrote 16.67% and "5% off" 20.83% —
     * a cut nobody asked for. A line above its baseline has no concession
     * behind it, so it anchors on today's price.
     */
    #[DataProvider('raisedLine')]
    public function testALineAboveItsBaselineAnchorsOnTodaysPrice(?float $percent, float $written): void
    {
        $line = static fn(float $price): QuoteLineSnapshot => new QuoteLineSnapshot(
            identity: new QuoteLineIdentity('line-1'),
            quantity: 10,
            unitPriceNet: $price,
            totalNet: $price * 10,
        );

        self::assertEqualsWithDelta(
            $written,
            QuoteWidePercent::of(
                new ProposedOffer(orderTotalNet: 1200.0, price: new OfferedPrice(discountPercent: $percent)),
                [$line(120.0)],
                [$line(100.0)],
            ),
            1e-6,
        );
    }

    /** @return iterable<string, array{?float, float}> */
    public static function raisedLine(): iterable
    {
        yield 'a hold' => [null, 0.0];
        yield '5% off' => [5.0, 5.0];
    }
}
