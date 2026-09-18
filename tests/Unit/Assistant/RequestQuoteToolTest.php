<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Assistant;

use MerchantQuoteAgentPlugin\Assistant\AssistantAskStamp;
use MerchantQuoteAgentPlugin\Assistant\RequestQuoteTool;
use MerchantQuoteAgentPlugin\Bridge\BuyerQuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteSnapshot;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Ucp\Sdk\Exception\ValidationException;

final class RequestQuoteToolTest extends TestCase
{
    private ?array $lastLineItems = null;

    private ?string $lastComment = null;

    /** @var list<array{0: string, 1: array<string, mixed>|null}> */
    private array $stampCalls = [];

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

    /**
     * The guard at RequestQuoteTool that calls the stamp only when targets
     * are present, with the exact $targetSource the caller passed — not a
     * hardcoded default.
     */
    public function testATargetStampsTheAssistantProposedSource(): void
    {
        $this->tool()->__invoke(
            '98 each would work',
            [['product_id' => 'prod-1', 'unit_price' => 98.0]],
            'assistant_proposed',
        );

        self::assertSame([['quote-1', ['merchantQuoteAgentAssistantAsk' => 'assistant_proposed']]], $this->stampCalls);
    }

    public function testATargetStampsTheBuyerStatedSource(): void
    {
        $this->tool()->__invoke(
            '98 each would work',
            [['product_id' => 'prod-1', 'unit_price' => 98.0]],
            'buyer_stated',
        );

        self::assertSame([['quote-1', ['merchantQuoteAgentAssistantAsk' => 'buyer_stated']]], $this->stampCalls);
    }

    public function testNoTargetsDoesNotStamp(): void
    {
        $this->tool()->__invoke('Can you do better on these?');

        self::assertSame([], $this->stampCalls);
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

        $quoteGateway = $this->createMock(QuoteGatewayInterface::class);
        $quoteGateway
            ->method('updateQuote')
            ->willReturnCallback(function (string $quoteId, QuoteUpdate $update): void {
                $this->stampCalls[] = [$quoteId, $update->customFields];
            });

        return new RequestQuoteTool(
            $gateway,
            $this->createMock(SalesChannelContext::class),
            new AssistantAskStamp(new NullLogger(), $quoteGateway),
        );
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
