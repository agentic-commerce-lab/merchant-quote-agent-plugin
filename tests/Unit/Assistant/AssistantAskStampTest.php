<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Assistant;

use MerchantQuoteAgentPlugin\Assistant\AssistantAskStamp;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteSnapshot;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

/**
 * Who wrote the figure, recorded next to the quote.
 *
 * The buyer may have typed "98 each", or the assistant may have proposed 10%
 * and the buyer agreed. Both reach the policy engine as the same number, so
 * without this the decision log attributes a model's figure to a person.
 */
final class AssistantAskStampTest extends TestCase
{
    public function testItRecordsTheSourceOnTheQuote(): void
    {
        $written = null;
        $gateway = $this->createMock(QuoteGatewayInterface::class);
        $gateway
            ->method('updateQuote')
            ->willReturnCallback(static function (string $quoteId, QuoteUpdate $update) use (&$written): void {
                $written = [$quoteId, $update->customFields];
            });

        (new AssistantAskStamp(new NullLogger(), $gateway))->stamp(self::snapshot(), 'assistant_proposed');

        self::assertSame(['quote-1', ['merchantQuoteAgentAssistantAsk' => 'assistant_proposed']], $written);
    }

    public function testWithoutAGatewayItDoesNothing(): void
    {
        $this->expectNotToPerformAssertions();

        (new AssistantAskStamp(new NullLogger()))->stamp(self::snapshot(), 'buyer_stated');
    }

    /**
     * Fail-open, like A2cnSessionStamp: the quote exists and the buyer is
     * waiting on it. Losing the provenance note is a logged warning; losing
     * the buyer their quote over one would not be a trade worth making.
     */
    public function testAFailedWriteIsLoggedAndSwallowed(): void
    {
        $gateway = $this->createMock(QuoteGatewayInterface::class);
        $gateway->method('updateQuote')->willThrowException(new \RuntimeException('database gone'));

        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $levels = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->levels[] = (string) $level;
            }
        };

        (new AssistantAskStamp($logger, $gateway))->stamp(self::snapshot(), 'assistant_proposed');

        self::assertContains('warning', $logger->levels);
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
