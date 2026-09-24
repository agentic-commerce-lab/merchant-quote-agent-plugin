<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Emitter;

use MerchantQuoteAgentPlugin\Audit\TraceKind;
use MerchantQuoteAgentPlugin\Audit\TraceWrite;
use MerchantQuoteAgentPlugin\Audit\TraceWriterInterface;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/** Logs emission diagnostics and records its single durable outcome. */
final class SellerActJournal extends AbstractLogger
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly TraceWriterInterface $traces,
    ) {}

    #[\Override]
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->logger->log($level, $message, $context);
    }

    public function outcome(QuoteSnapshot $snapshot, EmissionOutcome $outcome): void
    {
        try {
            $act = $outcome->act;
            $this->traces->write(
                new TraceWrite(
                    TraceKind::SellerAct,
                    [
                        'sessionId' =>
                            $act?->sessionId() ?? ActChain::read($snapshot->lifecycle->customFields)->sessionId(),
                        'seq' => $act?->sequenceNumber(),
                        'actType' => $act?->messageType(),
                        'offerHash' => $act?->hash(),
                        'result' => $outcome->status->value,
                    ],
                    $act?->raw(),
                    $snapshot->identity->quoteId,
                    $snapshot->identity->customerId,
                ),
            );
        } catch (\Throwable $error) {
            $this->logger->error('A seller act trace could not be recorded.', [
                'quoteId' => $snapshot->identity->quoteId,
                'exception' => $error,
            ]);
        }
    }
}
