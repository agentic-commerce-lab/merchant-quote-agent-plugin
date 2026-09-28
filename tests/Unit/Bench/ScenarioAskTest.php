<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bench;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Tests\Bench\ScenarioAsk;
use PHPUnit\Framework\TestCase;

final class ScenarioAskTest extends TestCase
{
    public function testAPlaceholderRendersAsAFactorOfTheUnitPrice(): void
    {
        self::assertSame('Can you get to 71.20 a unit?', ScenarioAsk::render(
            'Can you get to {unit*0.89} a unit?',
            80.0,
        ));
    }

    public function testEveryPlaceholderInTheTextIsRendered(): void
    {
        self::assertSame('45.00 or 40.00', ScenarioAsk::render('{unit*0.9} or {unit*0.8}', 50.0));
    }

    public function testTextWithoutAPlaceholderIsUntouched(): void
    {
        self::assertSame('Could you do 5% off?', ScenarioAsk::render('Could you do 5% off?', 80.0));
    }

    public function testTheUnitPriceIsTheStoredLineTotalOverQuantity(): void
    {
        // 3 x 10.05 gross stored as 30.15, net 25.34, so a cent-rounded
        // unitPriceNet of 8.45. Rebuilding the unit price from that
        // (8.45 / ratio x 0.89) renders 8.95; the stored 10.05 renders 8.94.
        $line = self::line(unitPriceNet: 8.45, totalNet: 25.34, netRatio: 25.34 / 30.15, stored: 30.15);

        self::assertSame('8.94', ScenarioAsk::forLine('{unit*0.89}', $line));
    }

    public function testWithoutAStoredTotalTheNetTotalIsMovedIntoTheQuotesPriceSpace(): void
    {
        $line = self::line(unitPriceNet: 24.0, totalNet: 72.0, netRatio: 0.8, stored: null);

        self::assertSame('30.00', ScenarioAsk::forLine('{unit*1}', $line));
    }

    public function testWithoutALineTheAskIsSentAsWritten(): void
    {
        self::assertSame('{unit*0.9}', ScenarioAsk::forLine('{unit*0.9}', null));
    }

    private static function line(
        float $unitPriceNet,
        float $totalNet,
        float $netRatio,
        ?float $stored,
    ): QuoteLineSnapshot {
        return new QuoteLineSnapshot(
            identity: new QuoteLineIdentity('line-1', 'Widget', 'prod-1'),
            quantity: 3,
            unitPriceNet: $unitPriceNet,
            totalNet: $totalNet,
            netRatio: $netRatio,
            totalInQuotePriceSpace: $stored,
        );
    }
}
