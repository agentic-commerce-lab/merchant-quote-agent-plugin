<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Ucp\Quote;

use MerchantQuoteAgentPlugin\Bridge\BuyerQuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteCapability;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteCapabilityDescriptor;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteList;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Ucp\Sdk\Exception\UnsupportedCapabilityException;

/**
 * Guard-then-delegate: every operation either forwards to the gateway
 * untouched or fails as unsupported before it gets there.
 */
final class QuoteCapabilityTest extends TestCase
{
    public function testItStillDescribesItselfThroughTheSharedDescriptor(): void
    {
        $descriptor = (new QuoteCapability())->describe();

        self::assertSame(QuoteCapabilityDescriptor::NAME, $descriptor->name);
        self::assertSame(QuoteCapabilityDescriptor::SCHEMA_PATH, $descriptor->schemaUrl);
    }

    public function testAnUnwiredGatewayMakesEveryOperationUnsupported(): void
    {
        $capability = new QuoteCapability();

        $this->expectException(UnsupportedCapabilityException::class);

        $capability->getQuote($this->createMock(SalesChannelContext::class), 'any-id');
    }

    public function testAnUnlicensedGatewayMakesEveryOperationUnsupported(): void
    {
        $gateway = $this->createMock(BuyerQuoteGatewayInterface::class);
        $gateway->method('isAvailable')->willReturn(false);

        $capability = new QuoteCapability($gateway);

        $this->expectException(UnsupportedCapabilityException::class);

        $capability->getQuote($this->createMock(SalesChannelContext::class), 'any-id');
    }

    public function testRequestQuoteDelegatesToTheGateway(): void
    {
        $context = $this->createMock(SalesChannelContext::class);
        $snapshot = QuoteCapabilityFixture::snapshot();
        $lineItems = [['product_id' => 'product-id', 'quantity' => 5]];

        $gateway = $this->availableGateway();
        $gateway
            ->expects(self::once())
            ->method('requestQuote')
            ->with($context, $lineItems, 'volume pricing please')
            ->willReturn($snapshot);

        $capability = new QuoteCapability($gateway);

        self::assertSame($snapshot, $capability->requestQuote($context, $lineItems, 'volume pricing please'));
    }

    public function testGetQuoteDelegatesToTheGateway(): void
    {
        $context = $this->createMock(SalesChannelContext::class);
        $snapshot = QuoteCapabilityFixture::snapshot();

        $gateway = $this->availableGateway();
        $gateway->expects(self::once())->method('getQuote')->with($context, 'quote-id')->willReturn($snapshot);

        $capability = new QuoteCapability($gateway);

        self::assertSame($snapshot, $capability->getQuote($context, 'quote-id'));
    }

    public function testListQuotesDelegatesToTheGateway(): void
    {
        $context = $this->createMock(SalesChannelContext::class);
        $list = new QuoteList([QuoteCapabilityFixture::snapshot()], 3, 25, 1);

        $gateway = $this->availableGateway();
        $gateway->expects(self::once())->method('listQuotes')->with($context, 25, 1)->willReturn($list);

        $capability = new QuoteCapability($gateway);

        self::assertSame($list, $capability->listQuotes($context, 25, 1));
    }

    public function testCounterQuoteDelegatesToTheGateway(): void
    {
        $context = $this->createMock(SalesChannelContext::class);
        $snapshot = QuoteCapabilityFixture::snapshot();
        $lineItems = [['id' => 'line-item-id', 'requested_unit_price' => 8.5]];

        $gateway = $this->availableGateway();
        $gateway
            ->expects(self::once())
            ->method('counterQuote')
            ->with($context, 'quote-id', $lineItems, 'still too high')
            ->willReturn($snapshot);

        $capability = new QuoteCapability($gateway);

        self::assertSame($snapshot, $capability->counterQuote($context, 'quote-id', $lineItems, 'still too high'));
    }

    public function testAcceptQuoteDelegatesToTheGateway(): void
    {
        $context = $this->createMock(SalesChannelContext::class);
        $snapshot = QuoteCapabilityFixture::snapshot();

        $gateway = $this->availableGateway();
        $gateway->expects(self::once())->method('acceptQuote')->with($context, 'quote-id')->willReturn($snapshot);

        $capability = new QuoteCapability($gateway);

        self::assertSame($snapshot, $capability->acceptQuote($context, 'quote-id'));
    }

    public function testDeclineQuoteDelegatesToTheGateway(): void
    {
        $context = $this->createMock(SalesChannelContext::class);
        $snapshot = QuoteCapabilityFixture::snapshot();

        $gateway = $this->availableGateway();
        $gateway
            ->expects(self::once())
            ->method('declineQuote')
            ->with($context, 'quote-id', 'no longer needed')
            ->willReturn($snapshot);

        $capability = new QuoteCapability($gateway);

        self::assertSame($snapshot, $capability->declineQuote($context, 'quote-id', 'no longer needed'));
    }

    private function availableGateway(): BuyerQuoteGatewayInterface&\PHPUnit\Framework\MockObject\MockObject
    {
        $gateway = $this->createMock(BuyerQuoteGatewayInterface::class);
        $gateway->method('isAvailable')->willReturn(true);

        return $gateway;
    }
}
