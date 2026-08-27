<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data;

final readonly class QuoteIdentity
{
    public function __construct(
        public string $quoteId,
        public string $quoteNumber,
        public string $currencyIso,
        public string $salesChannelId,
    ) {}
}
