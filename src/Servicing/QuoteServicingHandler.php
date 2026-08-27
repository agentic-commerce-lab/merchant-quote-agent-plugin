<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Bridge\QuoteNotFoundException;
use MerchantQuoteAgentPlugin\Servicing\Attempt\ServicingAttemptStoreInterface;
use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;
use MerchantQuoteAgentPlugin\Servicing\Exception\QuoteServicingAttemptsExhaustedException;
use MerchantQuoteAgentPlugin\Servicing\Exception\QuoteServicingBusyException;
use MerchantQuoteAgentPlugin\Servicing\Exception\QuoteServicingUnavailableException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handles asynchronous quote servicing messages with per-quote concurrency
 * locking and optimistic revision validation.
 */
#[AsMessageHandler]
final readonly class QuoteServicingHandler
{
    private const LOCK_TTL_SECONDS = 300.0;
    private const MAX_PIPELINE_DELIVERIES = 4;

    public function __construct(
        private LockFactory $lockFactory,
        private QuoteServicingPipelineInterface $pipeline,
        private LoggerInterface $logger,
        private ?QuoteGatewayInterface $gateway,
        private ServicingAttemptStoreInterface $attemptStore,
    ) {}

    /**
     * @throws QuoteServicingUnavailableException when SwagCommercial cannot provide the quote gateway
     * @throws QuoteServicingBusyException when another worker currently services the quote
     * @throws QuoteServicingAttemptsExhaustedException when the message exceeds its delivery bound
     */
    public function __invoke(ServiceQuoteMessage $message): void
    {
        if ($this->gateway === null) {
            $this->logger->warning('Quote servicing skipped: SwagCommercial is unavailable or unlicensed.', [
                'quoteId' => $message->quoteId,
            ]);

            throw QuoteServicingUnavailableException::gatewayNotAvailable($message->quoteId);
        }

        $lock = $this->lockFactory->createLock('quote_servicing_' . $message->quoteId, self::LOCK_TTL_SECONDS);
        if (!$lock->acquire(blocking: false)) {
            $this->logger->info('Quote servicing already in flight; retrying this delivery.', [
                'quoteId' => $message->quoteId,
            ]);

            throw QuoteServicingBusyException::quoteAlreadyActive($message->quoteId);
        }

        try {
            $deliveryCount = $this->attemptStore->recordDelivery($message->messageId);
            if ($deliveryCount > self::MAX_PIPELINE_DELIVERIES) {
                $this->logger->warning('Quote servicing delivery attempts exhausted.', [
                    'messageId' => $message->messageId,
                    'quoteId' => $message->quoteId,
                    'deliveryCount' => $deliveryCount,
                ]);

                throw QuoteServicingAttemptsExhaustedException::deliveryLimitExceeded(
                    $message->messageId,
                    $message->quoteId,
                    $deliveryCount,
                );
            }

            try {
                $snapshot = $this->gateway->fetchSnapshot($message->quoteId);
            } catch (QuoteNotFoundException) {
                $this->attemptStore->completeDelivery($message->messageId);

                return;
            }

            if (!$snapshot->revision->matches($message->revision)) {
                $this->logger->info('Quote revision mismatch: quote was modified since message was queued. Aborting stale pass.', [
                    'quoteId' => $message->quoteId,
                    'messageRevision' => $message->revision->updatedAt->format(\DateTimeInterface::RFC3339_EXTENDED),
                    'currentRevision' => $snapshot->revision->updatedAt->format(\DateTimeInterface::RFC3339_EXTENDED),
                ]);

                $this->attemptStore->completeDelivery($message->messageId);

                return;
            }

            $state = $snapshot->lifecycle->stateTechnicalName;
            if (!QuoteServicingStates::isServiceable($state)) {
                $this->logger->info('Quote state is not serviceable, skipping.', [
                    'quoteId' => $message->quoteId,
                    'state' => $state,
                ]);

                $this->attemptStore->completeDelivery($message->messageId);

                return;
            }

            $this->pipeline->service($snapshot, $this->gateway);
            $this->attemptStore->completeDelivery($message->messageId);
        } finally {
            $lock->release();
        }
    }
}
