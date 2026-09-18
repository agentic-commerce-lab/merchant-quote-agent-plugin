<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Assistant;

use MerchantQuoteAgentPlugin\Assistant\QuoteStatusTool;
use MerchantQuoteAgentPlugin\Bridge\BuyerQuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteList;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteSnapshot;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

final class QuoteStatusToolTest extends TestCase
{
    public function testWithoutANumberItReadsTheNewestQuote(): void
    {
        $result = $this->tool([self::snapshot('Q1002'), self::snapshot('Q1001')])->__invoke();

        self::assertSame('replied', $result['state']);
    }

    public function testItFindsAQuoteByNumber(): void
    {
        $result = $this->tool([self::snapshot('Q1002'), self::snapshot('Q1001')])->__invoke('Q1001');

        self::assertSame('replied', $result['state']);
    }

    /**
     * A model will invent a quote number sooner or later. `not_found` with a
     * note beats an exception surfacing to the shopper as a broken chat.
     */
    public function testAnUnknownNumberIsAStateRatherThanAnError(): void
    {
        $result = $this->tool([self::snapshot('Q1002')])->__invoke('Q9999');

        self::assertSame('not_found', $result['state']);
    }

    /**
     * Every money value leaves as a formatted string. A float in this array is
     * a number the model may re-round, and a re-rounded price shown to a
     * shopper is a wrong price.
     */
    public function testEveryValueIsAString(): void
    {
        foreach ($this->tool([self::snapshot('Q1002')])->__invoke() as $value) {
            self::assertIsString($value);
        }
    }

    /** @param list<QuoteSnapshot> $quotes */
    private function tool(array $quotes): QuoteStatusTool
    {
        $gateway = $this->createMock(BuyerQuoteGatewayInterface::class);
        $gateway->method('isAvailable')->willReturn(true);
        $gateway->method('listQuotes')->willReturn(new QuoteList($quotes, \count($quotes), 25, 1));

        return new QuoteStatusTool($gateway, $this->createMock(SalesChannelContext::class));
    }

    private static function snapshot(string $number): QuoteSnapshot
    {
        return new QuoteSnapshot(
            id: 'quote-' . $number,
            quoteNumber: $number,
            state: 'replied',
            expirationDate: '2026-10-01T00:00:00+00:00',
            currency: 'EUR',
            totalGross: 238.00,
            totalNet: 200.00,
            taxStatus: 'net',
            lineItems: [],
            comments: [],
        );
    }
}
