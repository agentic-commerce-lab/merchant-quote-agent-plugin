<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Emitter;

use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Bridge\QuoteNotFoundException;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingLock;
use MerchantQuoteAgentPlugin\Servicing\ServiceQuoteHandler;
use MerchantQuoteAgentPlugin\Servicing\ServicingJournal;
use MerchantQuoteAgentPlugin\Servicing\SkipContext;
use MerchantQuoteAgentPlugin\Servicing\SkipReason;
use MerchantQuoteAgentPlugin\Servicing\SkipSource;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

/**
 * Runs one observation, under the same per-quote lock quote servicing uses.
 *
 * The lock is the whole reason this is a handler and not an inline call: two
 * overlapping observations would both compute the same next sequence and race
 * two different signed payloads onto the same wire key.
 *
 * A busy lock is a RETRY, exactly as ServiceQuoteHandler treats it, at the
 * same delay. It is not the edge case it looks like: the `replied` transition
 * that queues this message fires INSIDE ServiceQuoteHandler's own lock, so a
 * worker that consumes the message before servicing finishes finds the lock
 * held — and since OfferVisibleStateSubscriber is the only trigger and the
 * quote stays in `replied`, dropping it meant the act was never emitted at
 * all, silently, on the agent's own reply.
 *
 * That contention is the ONLY throwing path. Every other failure — no
 * gateway or emitter, a vanished quote, any other \Throwable — is logged and
 * swallowed: evidence must never park a message because our own code broke.
 */
#[AsMessageHandler]
final readonly class ObserveQuoteHandler
{
    public function __construct(
        private QuoteServicingLock $locks,
        private LoggerInterface $logger,
        private ?QuoteGatewayInterface $gateway = null,
        private ?SellerActEmitter $emitter = null,
        private ?ServicingJournal $journal = null,
    ) {}

    /** @throws RecoverableMessageHandlingException when another worker holds the quote */
    public function __invoke(ObserveQuoteMessage $message): void
    {
        $gateway = $this->gateway;
        $emitter = $this->emitter;
        if ($gateway === null || $emitter === null) {
            // SwagCommercial absent or unlicensed. Servicing parks its message
            // in this case because a quote went unanswered; evidence has no
            // such duty, so this is a debug line and nothing more.
            $this->logger->debug('A2CN observation skipped: no commercial quote gateway.', [
                'quoteId' => $message->quoteId,
            ]);
            $this->skip(SkipReason::NoGateway, $message);

            return;
        }

        $lock = $this->locks->for($message->quoteId);
        if (!$lock->acquire()) {
            throw new RecoverableMessageHandlingException(
                \sprintf('Quote %s is claimed elsewhere; the A2CN observation is deferred.', $message->quoteId),
                retryDelay: ServiceQuoteHandler::BUSY_RETRY_DELAY_MS,
            );
        }

        try {
            $outcome = $emitter->observe($gateway->fetchSnapshot($message->quoteId), new \DateTimeImmutable());
            $this->logger->debug('A2CN observation finished.', [
                'quoteId' => $message->quoteId,
                'outcome' => $outcome->status->value,
            ]);
        } catch (QuoteNotFoundException $error) {
            $this->skip(SkipReason::QuoteNotFound, $message);
            $this->logger->info('A2CN observation skipped: the quote is gone.', [
                'quoteId' => $message->quoteId,
                'exception' => $error,
            ]);
        } catch (\Throwable $error) {
            $this->skip(SkipReason::ObservationFailed, $message);
            $this->logger->error('A2CN observation failed outside the emitter.', [
                'quoteId' => $message->quoteId,
                'exception' => $error,
            ]);
        } finally {
            $lock->release();
        }
    }

    private function skip(SkipReason $reason, ObserveQuoteMessage $message): void
    {
        $this->journal?->skip(SkipSource::Observer, $reason, SkipContext::forQuoteId($message->quoteId));
    }
}
