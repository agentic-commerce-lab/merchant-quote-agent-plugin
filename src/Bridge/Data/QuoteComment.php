<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data;

/**
 * Authorship fields identify buyer and staff comments when present. Agent
 * comments are author-less in the live shop and use their exact DAL row id as
 * the separate re-entrancy discriminator.
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
