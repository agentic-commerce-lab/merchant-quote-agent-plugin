<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

/**
 * Which sales channel, language, currency and domain a UCP request landed on.
 * Everything the Shopware context service needs, and nothing else.
 */
final readonly class SalesChannelResolution
{
    public function __construct(
        public string $salesChannelId,
        public string $languageId,
        public string $currencyId,
        public ?string $domainId,
    ) {}
}
