<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Servicing\Attempt\ServicingAttemptCollection;
use MerchantQuoteAgentPlugin\Servicing\Attempt\ServicingAttemptEntity;
use MerchantQuoteAgentPlugin\Servicing\Attempt\ServicingAttemptStoreInterface;
use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;
use MerchantQuoteAgentPlugin\Servicing\Exception\QuoteServicingAttemptsExhaustedException;
use MerchantQuoteAgentPlugin\Servicing\Exception\QuoteServicingBusyException;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingHandler;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingPipelineInterface;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Exception\RecoverableExceptionInterface;

final class ServicingConcurrencyTest extends IntegrationTestCase
{
    public function testConcurrentExecutionRetriesTheBusyDeliveryAndProducesOneOffer(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::quoteIdInState(static::getContainer(), Context::createDefaultContext(), 'open');
        $snapshot = $gateway->fetchSnapshot($quoteId);
        $lockFactory = self::lockFactory();
        $attemptStore = self::attemptStore();

        $pipeline = new class($lockFactory, $quoteId, $snapshot, $gateway, $attemptStore) implements
            QuoteServicingPipelineInterface {
            public int $offerCount = 0;
            public bool $busyDeliveryCaught = false;
            public ?int $retryDelay = null;
            public ?\Throwable $busyException = null;

            public function __construct(
                private readonly LockFactory $lockFactory,
                private readonly string $quoteId,
                private readonly QuoteSnapshot $snapshot,
                private readonly QuoteGatewayInterface $gateway,
                private readonly ServicingAttemptStoreInterface $attemptStore,
            ) {}

            #[\Override]
            public function service(QuoteSnapshot $snapshot, QuoteGatewayInterface $gateway): void
            {
                $this->offerCount++;

                $concurrentMessage = new ServiceQuoteMessage(
                    Uuid::randomHex(),
                    $this->quoteId,
                    $this->snapshot->identity->salesChannelId,
                    $this->snapshot->revision,
                );
                $concurrentHandler = new QuoteServicingHandler(
                    $this->lockFactory,
                    $this,
                    new NullLogger(),
                    $this->gateway,
                    $this->attemptStore,
                );

                try {
                    $concurrentHandler($concurrentMessage);
                } catch (QuoteServicingBusyException $exception) {
                    $this->busyDeliveryCaught = true;
                    $this->retryDelay = $exception->getRetryDelay();
                    $this->busyException = $exception;
                }
            }
        };

        $handler = self::handler($lockFactory, $pipeline, $gateway, $attemptStore);
        $message = new ServiceQuoteMessage(
            Uuid::randomHex(),
            $quoteId,
            $snapshot->identity->salesChannelId,
            $snapshot->revision,
        );

        $handler($message);

        self::assertTrue($pipeline->busyDeliveryCaught, 'The concurrent delivery was not asked to retry.');
        self::assertInstanceOf(RecoverableExceptionInterface::class, $pipeline->busyException);
        self::assertSame(5000, $pipeline->retryDelay);
        self::assertSame(1, $pipeline->offerCount, 'The in-flight duplicate produced a second offer.');
    }

    public function testStaleRevisionMessageAbortsWithoutMutatingQuote(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::quoteIdInState(static::getContainer(), Context::createDefaultContext(), 'open');
        $staleSnapshot = $gateway->fetchSnapshot($quoteId);

        $gateway->updateQuote($quoteId, new QuoteUpdate(expiresAt: new \DateTimeImmutable('+7 days')));
        $freshSnapshot = $gateway->fetchSnapshot($quoteId);
        self::assertFalse($staleSnapshot->revision->matches($freshSnapshot->revision));

        $pipeline = new class implements QuoteServicingPipelineInterface {
            public bool $executed = false;

            #[\Override]
            public function service(QuoteSnapshot $snapshot, QuoteGatewayInterface $gateway): void
            {
                $this->executed = true;
            }
        };
        $handler = self::handler(self::lockFactory(), $pipeline, $gateway, self::attemptStore());

        $handler(
            new ServiceQuoteMessage(
                Uuid::randomHex(),
                $quoteId,
                $staleSnapshot->identity->salesChannelId,
                $staleSnapshot->revision,
            ),
        );

        self::assertFalse($pipeline->executed, 'Pipeline executed despite the stale quote revision.');
        self::assertTrue($freshSnapshot->revision->matches($gateway->fetchSnapshot($quoteId)->revision));
    }

    public function testReplayCommentMessageDoesNotDuplicateACompletedOffer(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::quoteIdInState(static::getContainer(), Context::createDefaultContext(), 'open');
        $initialSnapshot = $gateway->fetchSnapshot($quoteId);

        $pipeline = new class implements QuoteServicingPipelineInterface {
            public int $offerCount = 0;

            #[\Override]
            public function service(QuoteSnapshot $snapshot, QuoteGatewayInterface $gateway): void
            {
                $this->offerCount++;
                $gateway->transition($snapshot->identity->quoteId, QuoteTransition::Process);
                $gateway->updateQuote(
                    $snapshot->identity->quoteId,
                    new QuoteUpdate(expiresAt: new \DateTimeImmutable('+14 days')),
                );
                $gateway->transition($snapshot->identity->quoteId, QuoteTransition::Sent);
            }
        };
        $handler = self::handler(self::lockFactory(), $pipeline, $gateway, self::attemptStore());

        $handler(
            new ServiceQuoteMessage(
                Uuid::randomHex(),
                $quoteId,
                $initialSnapshot->identity->salesChannelId,
                $initialSnapshot->revision,
            ),
        );

        self::assertSame(1, $pipeline->offerCount);
        self::assertSame('replied', $gateway->fetchSnapshot($quoteId)->lifecycle->stateTechnicalName);

        $handler(
            new ServiceQuoteMessage(
                Uuid::randomHex(),
                $quoteId,
                $initialSnapshot->identity->salesChannelId,
                $initialSnapshot->revision,
            ),
        );

        self::assertSame(1, $pipeline->offerCount, 'The replayed buyer-comment trigger produced a duplicate offer.');
        self::assertSame('replied', $gateway->fetchSnapshot($quoteId)->lifecycle->stateTechnicalName);
    }

    public function testDurableDeliveryLedgerExhaustsAfterFourPipelineCrashesAndReleasesTheLock(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::quoteIdInState(static::getContainer(), Context::createDefaultContext(), 'open');
        $snapshot = $gateway->fetchSnapshot($quoteId);
        $message = new ServiceQuoteMessage(
            Uuid::randomHex(),
            $quoteId,
            $snapshot->identity->salesChannelId,
            $snapshot->revision,
        );
        $attemptStore = self::attemptStore();
        $lockFactory = self::lockFactory();

        $pipeline = new class implements QuoteServicingPipelineInterface {
            public int $executionCount = 0;

            #[\Override]
            public function service(QuoteSnapshot $snapshot, QuoteGatewayInterface $gateway): void
            {
                $this->executionCount++;

                throw new \RuntimeException('Simulated worker crash.');
            }
        };
        $handler = self::handler($lockFactory, $pipeline, $gateway, $attemptStore);

        try {
            for ($delivery = 1; $delivery <= 4; $delivery++) {
                try {
                    $handler($message);
                    self::fail('A simulated pipeline crash was swallowed.');
                } catch (\RuntimeException $exception) {
                    self::assertSame('Simulated worker crash.', $exception->getMessage());
                }

                self::assertSame($delivery, self::persistedAttemptCount($message->messageId));
                self::assertQuoteLockAvailable($lockFactory, $quoteId);
            }

            try {
                $handler($message);
                self::fail('The fifth delivery was not parked as exhausted.');
            } catch (QuoteServicingAttemptsExhaustedException $exception) {
                self::assertStringContainsString('after 5 deliveries', $exception->getMessage());
            }

            self::assertSame(4, $pipeline->executionCount, 'The pipeline ran beyond its four-delivery bound.');
            self::assertSame(5, self::persistedAttemptCount($message->messageId));
            self::assertQuoteLockAvailable($lockFactory, $quoteId);
        } finally {
            $attemptStore->completeDelivery($message->messageId);
        }

        self::assertNull(self::persistedAttemptCount($message->messageId));
    }

    public function testLockReleaseAllowsSubsequentRuns(): void
    {
        $lockFactory = self::lockFactory();
        $quoteId = 'lock-release-test-' . bin2hex(random_bytes(4));

        $firstLock = $lockFactory->createLock('quote_servicing_' . $quoteId, 300.0);
        self::assertTrue($firstLock->acquire(false), 'Initial lock could not be acquired.');

        $secondLock = $lockFactory->createLock('quote_servicing_' . $quoteId, 300.0);
        self::assertFalse($secondLock->acquire(false), 'Lock was acquired while the first lock was still held.');

        $firstLock->release();
        self::assertTrue($secondLock->acquire(false), 'Lock could not be acquired after the first lock was released.');
        $secondLock->release();
    }

    private static function handler(
        LockFactory $lockFactory,
        QuoteServicingPipelineInterface $pipeline,
        QuoteGatewayInterface $gateway,
        ServicingAttemptStoreInterface $attemptStore,
    ): QuoteServicingHandler {
        return new QuoteServicingHandler($lockFactory, $pipeline, new NullLogger(), $gateway, $attemptStore);
    }

    private static function lockFactory(): LockFactory
    {
        $factory = static::getContainer()->get('lock.default.factory');
        self::assertInstanceOf(LockFactory::class, $factory);

        return $factory;
    }

    private static function attemptStore(): ServicingAttemptStoreInterface
    {
        $store = static::getContainer()->get(ServicingAttemptStoreInterface::class);
        self::assertInstanceOf(ServicingAttemptStoreInterface::class, $store);

        return $store;
    }

    private static function persistedAttemptCount(string $messageId): ?int
    {
        /** @var EntityRepository<ServicingAttemptCollection> $repository */
        $repository = static::getContainer()->get('merchant_quote_agent_servicing_attempt.repository');
        $attempt = $repository
            ->search(new Criteria([$messageId]), Context::createDefaultContext())
            ->getEntities()
            ->get($messageId);

        if ($attempt === null) {
            return null;
        }

        self::assertInstanceOf(ServicingAttemptEntity::class, $attempt);

        return $attempt->getAttemptCount();
    }

    private static function assertQuoteLockAvailable(LockFactory $lockFactory, string $quoteId): void
    {
        $lock = $lockFactory->createLock('quote_servicing_' . $quoteId, 300.0);
        self::assertTrue($lock->acquire(false), 'The handler did not release the per-quote lock.');
        $lock->release();
    }
}
