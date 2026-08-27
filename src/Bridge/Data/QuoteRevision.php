<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data;

/**
 * What the quote looked like, version-wise, when we read it. Pass one back
 * into a write to have the gateway refuse if the quote moved meanwhile.
 */
final readonly class QuoteRevision
{
    public function __construct(
        public string $versionId,
        public \DateTimeImmutable $updatedAt,
    ) {}

    public function matches(self $other): bool
    {
        return (
            $this->versionId === $other->versionId
            && $this->updatedAt->getTimestamp() === $other->updatedAt->getTimestamp()
        );
    }
}
