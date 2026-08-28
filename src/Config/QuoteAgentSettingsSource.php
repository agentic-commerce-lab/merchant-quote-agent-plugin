<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Config;

/** What ServicingPreflight needs from configuration, and nothing else. */
interface QuoteAgentSettingsSource
{
    /** @throws InvalidQuoteAgentConfiguration */
    public function forSalesChannel(?string $salesChannelId): ?QuoteAgentSettings;
}
