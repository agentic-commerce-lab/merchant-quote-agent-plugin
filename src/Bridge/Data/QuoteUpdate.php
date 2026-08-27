<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data;

/**
 * Quote-level fields to change. A null field means "leave it alone".
 *
 * `customFields` is SHALLOW-MERGED by the gateway, never replaced: the A2CN act
 * chain stores one act per top-level key so buyer and seller appends do not
 * collide, and a replacing write would destroy the counterparty's acts.
 */
final readonly class QuoteUpdate
{
    /** @param array<string, mixed>|null $customFields */
    public function __construct(
        public ?Discount $discount = null,
        public ?\DateTimeImmutable $expiresAt = null,
        public ?array $customFields = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->discount === null && $this->expiresAt === null && $this->customFields === null;
    }
}
