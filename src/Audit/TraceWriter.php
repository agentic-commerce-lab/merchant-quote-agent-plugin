<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Writes one outside-pass event as it happens. A failed audit write is logged
 * but cannot turn a skipped delivery into a Messenger retry.
 */
final readonly class TraceWriter implements TraceWriterInterface
{
    public function __construct(
        private EntityRepository $traces,
        private LoggerInterface $logger,
    ) {}

    #[\Override]
    public function write(TraceWrite $event): void
    {
        try {
            [$content, $cut] = $event->content === null
                ? [null, []]
                : TracePayload::capped(TracePayload::of($event->content));
            $declared = array_fill_keys($event->kind->metaKeys(), null);
            $meta = [...$declared, ...array_intersect_key($event->meta, $declared)];

            if ($cut !== []) {
                $meta['truncated'] = $cut;
            }

            $this->traces->create([[
                'id' => Uuid::randomHex(),
                'decisionId' => null,
                'quoteId' => $event->quoteId,
                'customerId' => $event->customerId,
                'kind' => $event->kind->value,
                'position' => null,
                'occurredAt' => new \DateTimeImmutable(),
                'meta' => $meta,
                'content' => $content,
            ]], Context::createDefaultContext());
        } catch (\Throwable $e) {
            $this->logger->error('An outside-pass trace event could not be recorded.', [
                'kind' => $event->kind->value,
                'quoteId' => $event->quoteId,
                'exception' => $e,
            ]);
        }
    }
}
