<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

/** What one erasure changed, so the merchant answering the request can say so. */
final readonly class Erasure
{
    public function __construct(
        public int $decisions,
        public int $traces,
    ) {}
}
