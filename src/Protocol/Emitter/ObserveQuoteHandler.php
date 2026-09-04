<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Emitter;

use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Bridge\QuoteNotFoundException;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingLock;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs one observation, under the same per-quote lock quote servicing uses.
 *
 * The lock is the whole reason this is a handler and not an inline call: two
 * overlapping observations would both compute the same next sequence and race
 * two different signed payloads onto the same wire key. A busy lock is not an
 * error — whoever holds it is doing this work — so the message is dropped
 * rather than retried: the next state change or the servicing pass that follows
 * will observe again, and emission is idempotent.
 *
 * Nothing here throws. Evidence must never park a message or fail a worker.
 */
#[AsMessageHandler]
final readonly class ObserveQuoteHandler
{
    public function __construct(
        private QuoteServicingLock $locks,
        private LoggerInterface $logger,
        private ?QuoteGatewayInterface $gateway = null,
        private ?SellerActEmitter $emitter = null,
    ) {}

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

            return;
        }

        $lock = $this->locks->for($message->quoteId);
        if (!$lock->acquire()) {
            $this->logger->debug('A2CN observation skipped: the quote is claimed elsewhere.', [
                'quoteId' => $message->quoteId,
            ]);

            return;
        }

        try {
            $outcome = $emitter->observe($gateway->fetchSnapshot($message->quoteId), new \DateTimeImmutable());
            $this->logger->debug('A2CN observation finished.', [
                'quoteId' => $message->quoteId,
                'outcome' => $outcome->status->value,
            ]);
        } catch (QuoteNotFoundException $error) {
            $this->logger->info('A2CN observation skipped: the quote is gone.', [
                'quoteId' => $message->quoteId,
                'exception' => $error,
            ]);
        } catch (\Throwable $error) {
            $this->logger->error('A2CN observation failed outside the emitter.', [
                'quoteId' => $message->quoteId,
                'exception' => $error,
            ]);
        } finally {
            $lock->release();
        }
    }
}
