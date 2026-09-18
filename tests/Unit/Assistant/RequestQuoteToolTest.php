<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Assistant;

use MerchantQuoteAgentPlugin\Assistant\RequestQuoteTool;
use MerchantQuoteAgentPlugin\Bridge\BuyerQuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteSnapshot;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Ucp\Sdk\Exception\ValidationException;

final class RequestQuoteToolTest extends TestCase
{
    private ?array $lastLineItems = null;

    private ?string $lastComment = null;

    /**
     * The tool returns the quote's identity and NOTHING about money. The
     * merchant agent replies minutes later, so any figure here would be the
     * buyer's own ask handed back — which is what a model turns into "I got
     * you 12% off".
     */
    public function testTheResultCarriesNoPrices(): void
    {
        $result = $this->tool()->__invoke('Can you do better on these?');

        self::assertSame(['quote_number', 'state', 'note'], array_keys($result));
        self::assertStringNotContainsString('%', $result['note']);
    }

    public function testATargetPriceBecomesAPriceOnlyLine(): void
    {
        $this->tool()->__invoke('98 each would work', [['product_id' => 'prod-1', 'unit_price' => 98.0]]);

        self::assertSame([['product_id' => 'prod-1', 'requested_unit_price' => 98.0]], $this->lastLineItems);
    }

    public function testAnInventedTargetSourceIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->tool()->__invoke('cheaper please', [], 'model_decided');
    }

    public function testTheCommentIsBounded(): void
    {
        $this->tool()->__invoke(str_repeat('a', 5_000));

        self::assertSame(2_000, mb_strlen((string) $this->lastComment));
    }

    private function tool(): RequestQuoteTool
    {
        $gateway = $this->createMock(BuyerQuoteGatewayInterface::class);
        $gateway->method('isAvailable')->willReturn(true);
        $gateway
            ->method('requestQuote')
            ->willReturnCallback(function (
                SalesChannelContext $context,
                array $lineItems,
                ?string $comment,
            ): QuoteSnapshot {
                $this->lastLineItems = $lineItems;
                $this->lastComment = $comment;

                return self::snapshot();
            });

        return new RequestQuoteTool($gateway, $this->createMock(SalesChannelContext::class));
    }

    private static function snapshot(): QuoteSnapshot
    {
        return new QuoteSnapshot(
            id: 'quote-1',
            quoteNumber: 'Q1001',
            state: 'open',
            expirationDate: null,
            currency: 'EUR',
            totalGross: 238.00,
            totalNet: 200.00,
            taxStatus: 'net',
            lineItems: [],
            comments: [],
        );
    }
}
