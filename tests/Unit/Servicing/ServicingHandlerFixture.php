<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteComment;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;
use MerchantQuoteAgentPlugin\Servicing\Data\ServicingTriggerReason;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingLock;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingPipelineInterface;
use MerchantQuoteAgentPlugin\Servicing\ServiceQuoteHandler;
use MerchantQuoteAgentPlugin\Servicing\ServicingPreflight;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

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
        ?ServicingPreflight $preflight = null,
    ): ServiceQuoteHandler {
        return new ServiceQuoteHandler(
            $locks ?? self::locks(),
            new NullLogger(),
            $preflight ?? ServicingSettingsFixture::preflightReturning(ServicingSettingsFixture::settings()),
            $gateway,
            $pipeline,
        );
    }

    public static function message(): ServiceQuoteMessage
    {
        return ServiceQuoteMessage::because('q1', ServicingTriggerReason::CommentWritten);
    }

    /** @param array<string, mixed> $customFields */
    public static function snapshot(array $customFields = [], string $state = 'open'): QuoteSnapshot
    {
        return QuoteSnapshotFixture::snapshot(state: $state, customFields: $customFields);
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

    /** @return iterable<string, array{0: string, 1: class-string<\Throwable>}> */
    public static function handoffRefusalScenarios(): iterable
    {
        yield 'attempt ceiling exceeded' => ['ceiling', UnrecoverableMessageHandlingException::class];
        yield 'quote locked by another worker' => ['held-lock', RecoverableMessageHandlingException::class];
    }

    /**
     * @return array{0: FakeQuoteGateway, 1: ?LockInterface}
     */
    public static function refusalScenario(string $scenario, QuoteServicingLock $locks): array
    {
        if ($scenario === 'held-lock') {
            // Returned rather than discarded: Symfony's Lock releases itself in
            // __destruct() when autoRelease is true, so a temporary that
            // nothing references would be garbage-collected — and its lock
            // released — before the handler ever tries to acquire it. The
            // caller is responsible for keeping index 1 alive.
            $held = $locks->for('q1');
            $held->acquire();

            return [new FakeQuoteGateway([self::snapshot()]), $held];
        }

        return [
            new FakeQuoteGateway([self::snapshot([
                ServiceQuoteHandler::ATTEMPTS_KEY => ServiceQuoteHandler::MAX_ATTEMPTS,
            ])]),
            null,
        ];
    }

    /** @return QuoteServicingPipelineInterface&object{passes: int} */
    public static function countingPipeline(): object
    {
        return new class implements QuoteServicingPipelineInterface {
            public int $passes = 0;

            #[\Override]
            public function service(
                QuoteSnapshot $snapshot,
                QuoteGatewayInterface $gateway,
                QuoteAgentSettings $settings,
            ): void {
                ++$this->passes;
            }
        };
    }
}
