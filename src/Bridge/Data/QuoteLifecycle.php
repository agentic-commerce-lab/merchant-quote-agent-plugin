<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data;

final readonly class QuoteLifecycle
{
    /** @param array<string, mixed> $customFields */
    public function __construct(
        public string $stateTechnicalName,
        public ?\DateTimeImmutable $expiresAt = null,
        public array $customFields = [],
    ) {}
}
