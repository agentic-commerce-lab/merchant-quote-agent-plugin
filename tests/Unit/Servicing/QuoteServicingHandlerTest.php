<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteContent;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTotals;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Servicing\Attempt\ServicingAttemptStoreInterface;
use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;
use MerchantQuoteAgentPlugin\Servicing\Exception\QuoteServicingAttemptsExhaustedException;
use MerchantQuoteAgentPlugin\Servicing\Exception\QuoteServicingBusyException;
use MerchantQuoteAgentPlugin\Servicing\Exception\QuoteServicingUnavailableException;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingHandler;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingPipelineInterface;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Messenger\Exception\RecoverableExceptionInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;

final class QuoteServicingHandlerTest extends TestCase
{
    private const MESSAGE_ID = 'message-018b449b2ba170a4a589cf8cb59a35e4';
    private const QUOTE_ID = 'quote-123';
    private const REVISION_ID = '018b449b2ba170a4a589cf8cb59a35e4';

    private LockFactory&MockObject $lockFactory;
    private QuoteServicingPipelineInterface&MockObject $pipeline;
    private LoggerInterface&MockObject $logger;
    private SharedLockInterface&MockObject $lock;
    private QuoteGatewayInterface&MockObject $gateway;
    private ServicingAttemptStoreInterface&MockObject $attemptStore;

    #[\Override]
    protected function setUp(): void
    {
        $this->lockFactory = $this->createMock(LockFactory::class);
        $this->pipeline = $this->createMock(QuoteServicingPipelineInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->lock = $this->createMock(SharedLockInterface::class);
        $this->gateway = $this->createMock(QuoteGatewayInterface::class);
        $this->attemptStore = $this->createMock(ServicingAttemptStoreInterface::class);
    }

    #[TestWith([1], 'first delivery')]
    #[TestWith([4], 'maximum delivery boundary')]
    public function testHandlesServiceMessageSuccessfully(int $deliveryCount): void
    {
        $revision = new QuoteRevision(self::REVISION_ID, new \DateTimeImmutable('2026-08-27 12:00:00.123456'));
        $message = new ServiceQuoteMessage(
            messageId: self::MESSAGE_ID,
            quoteId: self::QUOTE_ID,
            salesChannelId: 'sales-channel-456',
            revision: $revision,
        );
        $snapshot = $this->createSnapshot('open', $revision);
        $calls = [];

        $this->lockFactory
            ->expects(self::once())
            ->method('createLock')
            ->with('quote_servicing_' . self::QUOTE_ID, 300.0)
            ->willReturn($this->lock);
        $this->lock->expects(self::once())->method('acquire')->with(false)->willReturn(true);
        $this->lock->expects(self::once())->method('release');
        $this->attemptStore
            ->expects(self::once())
            ->method('recordDelivery')
            ->with(self::MESSAGE_ID)
            ->willReturnCallback(static function (string $messageId) use (&$calls, $deliveryCount): int {
                $calls[] = 'record:' . $messageId;

                return $deliveryCount;
            });
        $this->gateway
            ->expects(self::once())
            ->method('fetchSnapshot')
            ->with(self::QUOTE_ID)
            ->willReturnCallback(static function (string $quoteId) use (&$calls, $snapshot): QuoteSnapshot {
                $calls[] = 'fetch:' . $quoteId;

                return $snapshot;
            });
        $this->pipeline
            ->expects(self::once())
            ->method('service')
            ->with($snapshot, $this->gateway)
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = 'service';
            });
        $this->attemptStore
            ->expects(self::once())
            ->method('completeDelivery')
            ->with(self::MESSAGE_ID)
            ->willReturnCallback(static function (string $messageId) use (&$calls): void {
                $calls[] = 'complete:' . $messageId;
            });

        $handler = new QuoteServicingHandler(
            lockFactory: $this->lockFactory,
            pipeline: $this->pipeline,
            logger: $this->logger,
            gateway: $this->gateway,
            attemptStore: $this->attemptStore,
        );
        $handler($message);

        self::assertSame(
            [
                'record:' . self::MESSAGE_ID,
                'fetch:' . self::QUOTE_ID,
                'service',
                'complete:' . self::MESSAGE_ID,
            ],
            $calls,
        );
    }

    public function testThrowsRecoverableBusyExceptionWhenLockIsAlreadyHeld(): void
    {
        $message = new ServiceQuoteMessage(
            messageId: self::MESSAGE_ID,
            quoteId: self::QUOTE_ID,
            salesChannelId: 'sales-channel-456',
            revision: new QuoteRevision(self::REVISION_ID, new \DateTimeImmutable('2026-08-27 12:00:00.123456')),
        );

        $this->lockFactory
            ->expects(self::once())
            ->method('createLock')
            ->with('quote_servicing_' . self::QUOTE_ID, 300.0)
            ->willReturn($this->lock);
        $this->lock->expects(self::once())->method('acquire')->with(false)->willReturn(false);
        $this->lock->expects(self::never())->method('release');
        $this->logger
            ->expects(self::once())
            ->method('info')
            ->with('Quote servicing already in flight; retrying this delivery.', [
                'quoteId' => self::QUOTE_ID,
            ]);
        $this->attemptStore->expects(self::never())->method('recordDelivery');
        $this->attemptStore->expects(self::never())->method('completeDelivery');
        $this->gateway->expects(self::never())->method('fetchSnapshot');
        $this->pipeline->expects(self::never())->method('service');

        $handler = new QuoteServicingHandler(
            lockFactory: $this->lockFactory,
            pipeline: $this->pipeline,
            logger: $this->logger,
            gateway: $this->gateway,
            attemptStore: $this->attemptStore,
        );

        try {
            $handler($message);
            self::fail('Expected busy quote servicing to be retried.');
        } catch (QuoteServicingBusyException $exception) {
            self::assertInstanceOf(RecoverableExceptionInterface::class, $exception);
            self::assertSame(5000, $exception->getRetryDelay());
            self::assertSame(
                'Quote servicing is already active for quote "' . self::QUOTE_ID . '".',
                $exception->getMessage(),
            );
        }
    }

    public function testCompletesDeliveryWhenRevisionMismatches(): void
    {
        $messageRevision = new QuoteRevision(self::REVISION_ID, new \DateTimeImmutable('2026-08-27 12:00:00.100000'));
        $currentRevision = new QuoteRevision(self::REVISION_ID, new \DateTimeImmutable('2026-08-27 12:00:01.500000'));
        $message = new ServiceQuoteMessage(
            messageId: self::MESSAGE_ID,
            quoteId: self::QUOTE_ID,
            salesChannelId: 'sales-channel-456',
            revision: $messageRevision,
        );
        $snapshot = $this->createSnapshot('open', $currentRevision);

        $this->lockFactory->expects(self::once())->method('createLock')->willReturn($this->lock);
        $this->lock->expects(self::once())->method('acquire')->with(false)->willReturn(true);
        $this->lock->expects(self::once())->method('release');
        $this->attemptStore->expects(self::once())->method('recordDelivery')->with(self::MESSAGE_ID)->willReturn(1);
        $this->gateway->expects(self::once())->method('fetchSnapshot')->with(self::QUOTE_ID)->willReturn($snapshot);
        $this->logger
            ->expects(self::once())
            ->method('info')
            ->with(self::stringContains('revision mismatch'), [
                'quoteId' => self::QUOTE_ID,
                'messageRevision' => $messageRevision->updatedAt->format(\DateTimeInterface::RFC3339_EXTENDED),
                'currentRevision' => $currentRevision->updatedAt->format(\DateTimeInterface::RFC3339_EXTENDED),
            ]);
        $this->pipeline->expects(self::never())->method('service');
        $this->attemptStore->expects(self::once())->method('completeDelivery')->with(self::MESSAGE_ID);

        $handler = new QuoteServicingHandler(
            lockFactory: $this->lockFactory,
            pipeline: $this->pipeline,
            logger: $this->logger,
            gateway: $this->gateway,
            attemptStore: $this->attemptStore,
        );
        $handler($message);
    }

    public function testCompletesDeliveryWhenStateIsNotServiceable(): void
    {
        $revision = new QuoteRevision(self::REVISION_ID, new \DateTimeImmutable('2026-08-27 12:00:00.123456'));
        $message = new ServiceQuoteMessage(
            messageId: self::MESSAGE_ID,
            quoteId: self::QUOTE_ID,
            salesChannelId: 'sales-channel-456',
            revision: $revision,
        );
        $snapshot = $this->createSnapshot('replied', $revision);

        $this->lockFactory->expects(self::once())->method('createLock')->willReturn($this->lock);
        $this->lock->expects(self::once())->method('acquire')->with(false)->willReturn(true);
        $this->lock->expects(self::once())->method('release');
        $this->attemptStore->expects(self::once())->method('recordDelivery')->with(self::MESSAGE_ID)->willReturn(1);
        $this->gateway->expects(self::once())->method('fetchSnapshot')->with(self::QUOTE_ID)->willReturn($snapshot);
        $this->logger->expects(self::once())->method('info')->with(self::stringContains('not serviceable'), [
            'quoteId' => self::QUOTE_ID,
            'state' => 'replied',
        ]);
        $this->pipeline->expects(self::never())->method('service');
        $this->attemptStore->expects(self::once())->method('completeDelivery')->with(self::MESSAGE_ID);

        $handler = new QuoteServicingHandler(
            lockFactory: $this->lockFactory,
            pipeline: $this->pipeline,
            logger: $this->logger,
            gateway: $this->gateway,
            attemptStore: $this->attemptStore,
        );
        $handler($message);
    }

    public function testThrowsUnrecoverableUnavailableExceptionBeforeLockingWhenGatewayIsNull(): void
    {
        $message = new ServiceQuoteMessage(
            messageId: self::MESSAGE_ID,
            quoteId: self::QUOTE_ID,
            salesChannelId: 'sales-channel-456',
            revision: new QuoteRevision(self::REVISION_ID, new \DateTimeImmutable('2026-08-27 12:00:00.123456')),
        );

        $this->logger
            ->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('SwagCommercial is unavailable or unlicensed'), ['quoteId' => self::QUOTE_ID]);
        $this->lockFactory->expects(self::never())->method('createLock');
        $this->attemptStore->expects(self::never())->method('recordDelivery');
        $this->attemptStore->expects(self::never())->method('completeDelivery');
        $this->pipeline->expects(self::never())->method('service');

        $handler = new QuoteServicingHandler(
            lockFactory: $this->lockFactory,
            pipeline: $this->pipeline,
            logger: $this->logger,
            gateway: null,
            attemptStore: $this->attemptStore,
        );

        try {
            $handler($message);
            self::fail('Expected quote servicing to be unavailable.');
        } catch (QuoteServicingUnavailableException $exception) {
            self::assertInstanceOf(UnrecoverableExceptionInterface::class, $exception);
        }
    }

    public function testThrowsUnrecoverableExceptionWhenDeliveryAttemptsAreExhausted(): void
    {
        $message = new ServiceQuoteMessage(
            messageId: self::MESSAGE_ID,
            quoteId: self::QUOTE_ID,
            salesChannelId: 'sales-channel-456',
            revision: new QuoteRevision(self::REVISION_ID, new \DateTimeImmutable('2026-08-27 12:00:00.123456')),
        );

        $this->lockFactory
            ->expects(self::once())
            ->method('createLock')
            ->with('quote_servicing_' . self::QUOTE_ID, 300.0)
            ->willReturn($this->lock);
        $this->lock->expects(self::once())->method('acquire')->with(false)->willReturn(true);
        $this->lock->expects(self::once())->method('release');
        $this->attemptStore->expects(self::once())->method('recordDelivery')->with(self::MESSAGE_ID)->willReturn(5);
        $this->logger
            ->expects(self::once())
            ->method('warning')
            ->with('Quote servicing delivery attempts exhausted.', [
                'messageId' => self::MESSAGE_ID,
                'quoteId' => self::QUOTE_ID,
                'deliveryCount' => 5,
            ]);
        $this->gateway->expects(self::never())->method('fetchSnapshot');
        $this->pipeline->expects(self::never())->method('service');
        $this->attemptStore->expects(self::never())->method('completeDelivery');

        $handler = new QuoteServicingHandler(
            lockFactory: $this->lockFactory,
            pipeline: $this->pipeline,
            logger: $this->logger,
            gateway: $this->gateway,
            attemptStore: $this->attemptStore,
        );

        try {
            $handler($message);
            self::fail('Expected quote servicing delivery attempts to be exhausted.');
        } catch (QuoteServicingAttemptsExhaustedException $exception) {
            self::assertInstanceOf(UnrecoverableExceptionInterface::class, $exception);
            self::assertSame(
                'Quote servicing attempts exhausted for message "'
                . self::MESSAGE_ID
                . '" and quote "'
                . self::QUOTE_ID
                . '" after 5 deliveries.',
                $exception->getMessage(),
            );
        }
    }

    #[TestWith(['record'], 'attempt recording')]
    #[TestWith(['fetch'], 'snapshot fetch')]
    #[TestWith(['pipeline'], 'pipeline service')]
    public function testDoesNotCompleteDeliveryWhenProcessingThrows(string $failureStage): void
    {
        $revision = new QuoteRevision(self::REVISION_ID, new \DateTimeImmutable('2026-08-27 12:00:00.123456'));
        $message = new ServiceQuoteMessage(
            messageId: self::MESSAGE_ID,
            quoteId: self::QUOTE_ID,
            salesChannelId: 'sales-channel-456',
            revision: $revision,
        );
        $snapshot = $this->createSnapshot('open', $revision);
        $failure = new \RuntimeException($failureStage . ' failed.');

        $this->lockFactory->expects(self::once())->method('createLock')->willReturn($this->lock);
        $this->lock->expects(self::once())->method('acquire')->with(false)->willReturn(true);
        $this->lock->expects(self::once())->method('release');
        $recordExpectation = $this->attemptStore
            ->expects(self::once())
            ->method('recordDelivery')
            ->with(self::MESSAGE_ID);
        if ($failureStage === 'record') {
            $recordExpectation->willThrowException($failure);
            $this->gateway->expects(self::never())->method('fetchSnapshot');
            $this->pipeline->expects(self::never())->method('service');
        }
        if ($failureStage !== 'record') {
            $recordExpectation->willReturn(1);
        }
        if ($failureStage === 'fetch') {
            $this->gateway
                ->expects(self::once())
                ->method('fetchSnapshot')
                ->with(self::QUOTE_ID)
                ->willThrowException($failure);
            $this->pipeline->expects(self::never())->method('service');
        }
        if ($failureStage === 'pipeline') {
            $this->gateway->expects(self::once())->method('fetchSnapshot')->with(self::QUOTE_ID)->willReturn($snapshot);
            $this->pipeline->expects(self::once())->method('service')->willThrowException($failure);
        }
        $this->attemptStore->expects(self::never())->method('completeDelivery');

        $handler = new QuoteServicingHandler(
            lockFactory: $this->lockFactory,
            pipeline: $this->pipeline,
            logger: $this->logger,
            gateway: $this->gateway,
            attemptStore: $this->attemptStore,
        );

        try {
            $handler($message);
            self::fail('Expected the processing failure to propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
    }

    private function createSnapshot(string $state, QuoteRevision $revision): QuoteSnapshot
    {
        return new QuoteSnapshot(
            identity: new QuoteIdentity(self::QUOTE_ID, 'quote-num-1', 'EUR', 'sales-channel-456'),
            totals: new QuoteTotals(500.0, null),
            lifecycle: new QuoteLifecycle($state, null, []),
            content: new QuoteContent([], []),
            revision: $revision,
        );
    }
}
