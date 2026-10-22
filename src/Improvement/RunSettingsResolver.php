<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

use MerchantQuoteAgentPlugin\Config\InvalidQuoteAgentConfiguration;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsSource;

/**
 * null means "do not run tonight for this channel", for any of three reasons
 * this class deliberately does not distinguish to its caller: the improvement
 * feature is off, the agent underneath it is off, or the agent is
 * misconfigured (a dangling or archived strategy id, which
 * QuoteAgentSettingsSource::forSalesChannel() reports by THROWING
 * InvalidQuoteAgentConfiguration rather than returning null). That third case
 * must not escape this method: an uncaught throw here aborts
 * ImprovementGenerator's whole tick, so one misconfigured channel would stop
 * every channel after it from running, every night, until a human notices.
 *
 * The second read (the agent's own production settings) is expected to agree
 * with the first -- ImprovementSettingsReader::forSalesChannel() already
 * returned non-null only because the agent resolved -- but this class
 * re-checks rather than trust that invariant across two separate reads of
 * configuration that could change between them.
 */
final readonly class RunSettingsResolver
{
    public function __construct(
        private ImprovementSettingsReader $improvement,
        private QuoteAgentSettingsSource $agent,
    ) {}

    public function resolve(?string $salesChannelId): ?RunSettings
    {
        try {
            $improvement = $this->improvement->forSalesChannel($salesChannelId);

            if ($improvement === null) {
                return null;
            }

            $agent = $this->agent->forSalesChannel($salesChannelId);
        } catch (InvalidQuoteAgentConfiguration) {
            return null;
        }

        if ($agent === null) {
            return null;
        }

        return new RunSettings($improvement, $agent);
    }
}
