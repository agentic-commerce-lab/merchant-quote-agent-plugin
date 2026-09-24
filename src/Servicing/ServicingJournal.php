<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use MerchantQuoteAgentPlugin\Audit\TraceKind;
use MerchantQuoteAgentPlugin\Audit\TraceWrite;
use MerchantQuoteAgentPlugin\Audit\TraceWriterInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * Keeps the existing PSR-3 log lines and writes their durable skip twin.
 * Combining the two keeps the handler and preflight within the constructor
 * limit while giving both one place to record an exit.
 */
final class ServicingJournal extends AbstractLogger
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly TraceWriterInterface $writer,
    ) {}

    #[\Override]
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->logger->log($level, $message, $context);
    }

    public function skip(SkipSource $source, SkipReason $reason, SkipContext $context): void
    {
        try {
            $this->writer->write(
                new TraceWrite(
                    TraceKind::Skip,
                    [
                        'source' => $source->value,
                        'reason' => $reason->value,
                        'trigger' => $context->trigger,
                        'attempt' => $context->attempt,
                    ],
                    null,
                    $context->quoteId,
                    $context->customerId,
                ),
            );
        } catch (\Throwable $e) {
            // A custom writer can violate its no-throw contract. Even then,
            // recording must never change the delivery's disposition.
            $this->logger->error('A servicing skip could not be recorded.', [
                'quoteId' => $context->quoteId,
                'reason' => $reason->value,
                'exception' => $e,
            ]);
        }
    }
}
