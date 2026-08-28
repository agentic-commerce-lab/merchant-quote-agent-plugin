<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing\Data;

use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;
use MerchantQuoteAgentPlugin\Servicing\Data\ServicingTriggerReason;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

final class ServiceQuoteMessageTest extends TestCase
{
    public function testItRoutesToTheAsyncTransport(): void
    {
        $message = ServiceQuoteMessage::because('q1', ServicingTriggerReason::StateEntered);

        self::assertInstanceOf(AsyncMessageInterface::class, $message);
    }

    public function testItCarriesTheQuoteIdAndTheReasonsValue(): void
    {
        $message = ServiceQuoteMessage::because('q1', ServicingTriggerReason::CommentWritten);

        self::assertSame('q1', $message->quoteId);
        self::assertSame('comment_written', $message->reason);
    }

    /**
     * The payload is two plain strings on purpose: the `async` transport
     * serializes through messenger.transport.symfony_serializer, and a scalar
     * payload cannot fail to normalize. The enum types the call site; it does
     * not travel.
     */
    public function testTheSerialisedPayloadIsScalarOnly(): void
    {
        $message = ServiceQuoteMessage::because('q1', ServicingTriggerReason::StateEntered);

        foreach (get_object_vars($message) as $value) {
            self::assertIsString($value);
        }
    }
}
