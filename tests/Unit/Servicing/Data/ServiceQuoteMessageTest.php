<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing\Data;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

final class ServiceQuoteMessageTest extends TestCase
{
    public function testMessageHoldsPropertiesAndImplementsAsyncMessageInterface(): void
    {
        $revision = new QuoteRevision(
            '018b449b2ba170a4a589cf8cb59a35e4',
            new \DateTimeImmutable('2026-08-27 12:00:00.123456'),
        );
        $message = new ServiceQuoteMessage(
            messageId: '018d5f91e4707a83b2be1f6f624bfd2a',
            quoteId: 'quote-123',
            salesChannelId: 'sales-channel-456',
            revision: $revision,
        );

        self::assertInstanceOf(AsyncMessageInterface::class, $message);
        self::assertSame('018d5f91e4707a83b2be1f6f624bfd2a', $message->messageId);
        self::assertSame('quote-123', $message->quoteId);
        self::assertSame('sales-channel-456', $message->salesChannelId);
        self::assertSame($revision, $message->revision);
    }
}
