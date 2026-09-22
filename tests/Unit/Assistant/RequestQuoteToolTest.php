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

/**
 * @mago-expect lint:too-many-methods
 * Ten cases plus two private helpers (the tool builder and a snapshot
 * fixture) shared across them, including the malformed-target shape check,
 * the comment-only provenance stamp, and the gateway-refusal fix wave.
 */
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
        $this->tool()->__invoke('98 each would work', ['prod-1'], [98.0]);

        self::assertSame([['product_id' => 'prod-1', 'requested_unit_price' => 98.0]], $this->lastLineItems);
    }

    /**
     * A bad argument comes back as a result the model can act on, not as an
     * exception. A thrown one becomes a ToolExecutionException and kills the
     * shopper's chat turn — observed live on 2026-09-21, when a model sent the
     * old nested `targets` shape and the shopper's turn died instead of the
     * model being told to correct it.
     */
    public function testAnInventedTargetSourceIsRefusedRatherThanThrown(): void
    {
        $result = $this->tool()->__invoke('cheaper please', [], [], 'model_decided');

        self::assertSame('not_created', $result['state']);
        self::assertSame('', $result['quote_number']);
        self::assertStringContainsString('call this tool once more', $result['note']);
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
        $this->tool()->__invoke('98 each would work', ['prod-1'], [98.0], 'assistant_proposed');

        self::assertSame([['quote-1', ['merchantQuoteAgentAssistantAsk' => 'assistant_proposed']]], $this->stampCalls);
    }

    public function testATargetStampsTheBuyerStatedSource(): void
    {
        $this->tool()->__invoke('98 each would work', ['prod-1'], [98.0], 'buyer_stated');

        self::assertSame([['quote-1', ['merchantQuoteAgentAssistantAsk' => 'buyer_stated']]], $this->stampCalls);
    }

    public function testNoTargetsDoesNotStamp(): void
    {
        $this->tool()->__invoke('Can you do better on these?');

        self::assertSame([], $this->stampCalls);
    }

    /**
     * The comment is itself an ask channel: `Negotiation\AskInterpreter`
     * mines free text for price asks, and the model writes this comment when
     * it proposed the figure. So the stamp must fire on `assistant_proposed`
     * alone, even with no `targets` at all.
     */
    public function testAssistantProposedWithNoTargetsStillStamps(): void
    {
        $this->tool()->__invoke('the customer would like 15% off', [], [], 'assistant_proposed');

        self::assertSame([['quote-1', ['merchantQuoteAgentAssistantAsk' => 'assistant_proposed']]], $this->stampCalls);
    }

    /**
     * A malformed target from the model — missing a product id, or a
     * non-numeric price — must be a clean 422, not an undefined-array-key
     * warning that Shopware's debug handler promotes to a fatal error.
     */
    public function testAMalformedTargetIsRejected(): void
    {
        $result = $this->tool()->__invoke('cheaper please', [['product' => 'prod-1']], [98.0]);

        self::assertSame('not_created', $result['state']);
        self::assertSame([], $this->lastLineItems ?? []);
    }

    /** Parallel lists can disagree in a way one list of pairs could not. */
    public function testMismatchedTargetListLengthsAreRefused(): void
    {
        $result = $this->tool()->__invoke('98 each', ['prod-1', 'prod-2'], [98.0]);

        self::assertSame('not_created', $result['state']);
        self::assertStringContainsString('exactly one unit price', $result['note']);
    }

    /**
     * The mundane real path: an empty cart, or any other gateway refusal,
     * must not surface as a broken chat turn. It comes back as a structured
     * result the model can explain, with the same key set and no prices —
     * exactly what `QuoteStatusTool` already does for `not_found`.
     */
    public function testAGatewayRefusalBecomesAStructuredNotCreatedResult(): void
    {
        $gateway = $this->createMock(BuyerQuoteGatewayInterface::class);
        $gateway->method('isAvailable')->willReturn(true);
        $gateway
            ->method('requestQuote')
            ->willThrowException(
                new ValidationException('A quote request needs a line item or a cart.', [
                    '$.line_items must not be empty when the cart is empty',
                ]),
            );

        $tool = new RequestQuoteTool(
            $gateway,
            $this->createMock(SalesChannelContext::class),
            new AssistantAskStamp(new NullLogger()),
        );

        $result = $tool->__invoke('can I get a discount?');

        self::assertSame(['quote_number', 'state', 'note'], array_keys($result));
        self::assertSame('not_created', $result['state']);
        self::assertSame('', $result['quote_number']);
        self::assertStringContainsString('A quote request needs a line item or a cart.', $result['note']);
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
