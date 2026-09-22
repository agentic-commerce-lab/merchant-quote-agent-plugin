<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsSource;

/**
 * null means "do not run tonight for this channel", for either reason
 * ImprovementSettingsReader itself does not distinguish: the improvement
 * feature is off, or the agent underneath it is off or misconfigured. The
 * second read (the agent's own production settings) is expected to agree --
 * ImprovementSettingsReader::forSalesChannel() already returned non-null only
 * because the agent resolved -- but this class re-checks rather than trust
 * that invariant across two separate reads of configuration that could change
 * between them.
 */
final readonly class RunSettingsResolver
{
    public function __construct(
        private ImprovementSettingsReader $improvement,
        private QuoteAgentSettingsSource $agent,
    ) {}

    public function resolve(?string $salesChannelId): ?RunSettings
    {
        $improvement = $this->improvement->forSalesChannel($salesChannelId);

        if ($improvement === null) {
            return null;
        }

        $agent = $this->agent->forSalesChannel($salesChannelId);

        if ($agent === null) {
            return null;
        }

        return new RunSettings($improvement, $agent);
    }
}
