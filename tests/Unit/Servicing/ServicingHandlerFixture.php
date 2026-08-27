<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteComment;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;
use MerchantQuoteAgentPlugin\Servicing\Data\ServicingTriggerReason;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingLock;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingPipelineInterface;
use MerchantQuoteAgentPlugin\Servicing\ServiceQuoteHandler;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/** Builders for the servicing handler's unit tests. */
final class ServicingHandlerFixture
{
    private function __construct() {}

    public static function locks(): QuoteServicingLock
    {
        return new QuoteServicingLock(new LockFactory(new InMemoryStore()), 'redis://x');
    }

    public static function handler(
        FakeQuoteGateway $gateway,
        QuoteServicingPipelineInterface $pipeline,
        ?QuoteServicingLock $locks = null,
    ): ServiceQuoteHandler {
        return new ServiceQuoteHandler($locks ?? self::locks(), new NullLogger(), $gateway, $pipeline);
    }

    public static function message(): ServiceQuoteMessage
    {
        return ServiceQuoteMessage::because('q1', ServicingTriggerReason::CommentWritten);
    }

    /** @param array<string, mixed> $customFields */
    public static function snapshot(array $customFields = []): QuoteSnapshot
    {
        return QuoteSnapshotFixture::snapshot(customFields: $customFields);
    }

    public static function buyer(\DateTimeImmutable $createdAt): QuoteComment
    {
        return new QuoteComment('buyer ask', customerId: 'customer-1', createdAt: $createdAt);
    }

    /**
     * The last customFields write the gateway recorded, narrowed against
     * array_key_last()'s possibly-null key. Indexing `customFieldWrites`
     * bare leaves mago unable to prove the list is non-empty, so the assert
     * is the narrowing, not decoration.
     *
     * @return array<string, mixed>
     */
    public static function lastCustomFieldWrite(FakeQuoteGateway $gateway): array
    {
        $writes = $gateway->customFieldWrites;
        \assert($writes !== [], description: 'No customFields write was recorded on this gateway.');

        return array_pop($writes);
    }

    /** @return QuoteServicingPipelineInterface&object{passes: int} */
    public static function countingPipeline(): object
    {
        return new class implements QuoteServicingPipelineInterface {
            public int $passes = 0;

            #[\Override]
            public function service(QuoteSnapshot $snapshot, QuoteGatewayInterface $gateway): void
            {
                ++$this->passes;
            }
        };
    }
}
