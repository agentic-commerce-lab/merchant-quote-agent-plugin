<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data\History;

/** The company's order record, aggregated. Shared by the brief and the detail read. */
final readonly class OrderStats
{
    public function __construct(
        public int $count = 0,
        public float $lifetimeNet = 0.0,
        public ?\DateTimeImmutable $lastOrderAt = null,
    ) {}
}
