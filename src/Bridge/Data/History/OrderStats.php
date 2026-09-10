<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data\History;

/**
 * The company's order record, shared by brief and detail reads. Lifetime money
 * is null when currencies are mixed or not fully known; count and date remain
 * available. No currency conversion is implied. An empty record totals zero.
 */
final readonly class OrderStats
{
    public function __construct(
        public int $count = 0,
        public ?float $lifetimeNet = 0.0,
        public ?\DateTimeImmutable $lastOrderAt = null,
        public ?string $currencyIso = null,
        public ?string $unavailableReason = null,
    ) {}
}
