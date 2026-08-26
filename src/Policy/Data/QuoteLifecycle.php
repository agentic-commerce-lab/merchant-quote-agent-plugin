<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

final readonly class QuoteLifecycle
{
    public function __construct(
        public string $stateTechnicalName,
        public ?string $expirationDate = null,
    ) {}
}
