<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

/** One event written immediately, outside a decision pass. */
final readonly class TraceWrite
{
    /**
     * @param array<string, mixed> $meta
     * @param array<array-key, mixed>|null $content
     */
    public function __construct(
        public TraceKind $kind,
        public array $meta,
        public ?array $content = null,
        public ?string $quoteId = null,
        public ?string $customerId = null,
    ) {}
}
