<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Assistant;

use MerchantQuoteAgentPlugin\Assistant\AssistantAskStamp;
use MerchantQuoteAgentPlugin\Assistant\QuoteStatusTool;
use MerchantQuoteAgentPlugin\Assistant\RequestQuoteTool;
use MerchantQuoteAgentPlugin\Audit\TraceKind;
use MerchantQuoteAgentPlugin\Bridge\BuyerQuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeTraceWriter;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteList;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteSnapshot;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

final class AssistantToolTraceTest extends TestCase
{
    public function testRequestSuccessAndValidationRefusalEachRecordOneEvent(): void
    {
        $writer = new FakeTraceWriter();
        $gateway = $this->createMock(BuyerQuoteGatewayInterface::class);
        $gateway->method('requestQuote')->willReturn(self::snapshot());
        $tool = new RequestQuoteTool(
            $gateway,
            $this->createMock(SalesChannelContext::class),
            new AssistantAskStamp(new NullLogger()),
            $writer,
        );

        $created = $tool('Please quote this');
        $refused = $tool('Please quote this', targetSource: 'unknown');

        self::assertCount(2, $writer->events);
        self::assertSame(TraceKind::AssistantTool, $writer->events[0]->kind);
        self::assertSame('quote-1', $writer->events[0]->quoteId);
        self::assertSame(['tool' => 'request_quote', 'status' => 'open'], $writer->events[0]->meta);
        self::assertSame($created, $writer->events[0]->content['output']);
        self::assertSame('Please quote this', $writer->events[0]->content['input']['comment']);
        self::assertSame('not_created', $writer->events[1]->meta['status']);
        self::assertSame($refused, $writer->events[1]->content['output']);
    }

    public function testStatusLookupRecordsItsInputAndOutput(): void
    {
        $writer = new FakeTraceWriter();
        $gateway = $this->createMock(BuyerQuoteGatewayInterface::class);
        $gateway->method('listQuotes')->willReturn(new QuoteList([self::snapshot()], 1, 25, 1));
        $tool = new QuoteStatusTool($gateway, $this->createMock(SalesChannelContext::class), $writer);

        $output = $tool('Q-1');

        self::assertCount(1, $writer->events);
        self::assertSame(['tool' => 'quote_status', 'status' => 'open'], $writer->events[0]->meta);
        self::assertSame('quote-1', $writer->events[0]->quoteId);
        self::assertSame(['quoteNumber' => 'Q-1'], $writer->events[0]->content['input']);
        self::assertSame($output, $writer->events[0]->content['output']);
    }

    public function testAuditFailureDoesNotAlterToolResult(): void
    {
        $writer = new FakeTraceWriter();
        $writer->failure = new \RuntimeException('audit unavailable');
        $gateway = $this->createMock(BuyerQuoteGatewayInterface::class);
        $gateway->method('listQuotes')->willReturn(new QuoteList([], 0, 25, 1));

        $output = (new QuoteStatusTool($gateway, $this->createMock(SalesChannelContext::class), $writer))('Q-1');

        self::assertSame('not_found', $output['state']);
    }

    private static function snapshot(): QuoteSnapshot
    {
        return new QuoteSnapshot('quote-1', 'Q-1', 'open', null, 'EUR', null, null, 'gross', [], []);
    }
}
