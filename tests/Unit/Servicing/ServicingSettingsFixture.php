<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsSource;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Servicing\QuoteEscalator;
use MerchantQuoteAgentPlugin\Servicing\ServicingPreflight;
use Psr\Log\NullLogger;

/**
 * Settings and preflight doubles. Separate from ServicingHandlerFixture so
 * that class stays under mago's too-many-methods ceiling, and shared with
 * ServicingPreflightTest so one definition of "a valid configuration" serves
 * both the preflight's own tests and the handler's.
 */
final class ServicingSettingsFixture
{
    private function __construct() {}

    public static function settings(): QuoteAgentSettings
    {
        return new QuoteAgentSettings(
            new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 5.0)),
            llm: new ModelAccess('sk-test', 'https://api.openai.com/v1', 'gpt-4o-mini'),
            strategyPrompt: null,
        );
    }

    public static function preflightReturning(?QuoteAgentSettings $settings): ServicingPreflight
    {
        return self::preflight(static fn(): ?QuoteAgentSettings => $settings);
    }

    /** @param \Closure(): ?QuoteAgentSettings $outcome what the config source does when asked */
    public static function preflight(\Closure $outcome, ?QuoteEscalator $escalator = null): ServicingPreflight
    {
        $source = new class($outcome) implements QuoteAgentSettingsSource {
            /** @param \Closure(): ?QuoteAgentSettings $outcome */
            public function __construct(
                private readonly \Closure $outcome,
            ) {}

            #[\Override]
            public function forSalesChannel(?string $salesChannelId): ?QuoteAgentSettings
            {
                return ($this->outcome)();
            }
        };

        return new ServicingPreflight(
            $source,
            $escalator ?? new QuoteEscalator(settingsSource: $source),
            new NullLogger(),
        );
    }
}
