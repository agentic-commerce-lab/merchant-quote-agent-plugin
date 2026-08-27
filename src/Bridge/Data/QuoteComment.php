<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data;

/**
 * `createdById` / `customerId` are how a reader tells an agent-authored comment
 * from a buyer's. Task 8 records what they actually contain for our own writes.
 */
final readonly class QuoteComment
{
    public function __construct(
        public string $comment,
        public ?string $lineItemId = null,
        public ?string $createdById = null,
        public ?string $customerId = null,
        public ?\DateTimeImmutable $createdAt = null,
    ) {}
}
