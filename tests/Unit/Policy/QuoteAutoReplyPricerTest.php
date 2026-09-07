<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Policy\QuoteAutoReplyPricer;
use PHPUnit\Framework\TestCase;

final class QuoteAutoReplyPricerTest extends TestCase
{
    public function testItCountersAtDiscountFactorEvenWhenLinesHaveUngrantableAsks(): void
    {
        $pricer = new QuoteAutoReplyPricer();
        $snapshot = new QuoteSnapshot(
            currencyIso: 'EUR',
            totalNet: 1835.70,
            lines: [
                new QuoteLineSnapshot(
                    identity: new QuoteLineIdentity('line-1', 'Heavy Duty Steel Structure-AL'),
                    quantity: 10,
                    unitPriceNet: 183.57,
                    totalNet: 1835.70,
                    requestedUnitPrice: 152.36, // ~17% ask
                ),
            ],
            lifecycle: new QuoteLifecycle(stateTechnicalName: 'open'),
        );

        $details = $pricer->price(
            effective: $snapshot,
            discountPercent: 15.0,
            validityDays: 14,
            counteredRequestPercent: 17.0,
        );

        self::assertSame(15.0, $details->discountPercent);
        self::assertTrue($details->perLineAsks);
        self::assertCount(1, $details->lineUnitPricesNet);
        // At 15% discount: 183.57 * 0.85 = 156.03 (not the buyer's 152.36 ask!)
        self::assertSame(156.03, $details->lineUnitPricesNet[0]->unitPriceNet);
    }

    public function testItGrantsLineAskWhenNotCountering(): void
    {
        $pricer = new QuoteAutoReplyPricer();
        $snapshot = new QuoteSnapshot(
            currencyIso: 'EUR',
            totalNet: 1835.70,
            lines: [
                new QuoteLineSnapshot(
                    identity: new QuoteLineIdentity('line-1', 'Heavy Duty Steel Structure-AL'),
                    quantity: 10,
                    unitPriceNet: 183.57,
                    totalNet: 1835.70,
                    requestedUnitPrice: 170.00,
                ),
            ],
            lifecycle: new QuoteLifecycle(stateTechnicalName: 'open'),
        );

        $details = $pricer->price(
            effective: $snapshot,
            discountPercent: 7.39,
            validityDays: 14,
            counteredRequestPercent: null,
        );

        self::assertSame(170.00, $details->lineUnitPricesNet[0]->unitPriceNet);
    }
}
