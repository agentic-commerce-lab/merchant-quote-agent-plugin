<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Http;

use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsSource;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use Symfony\Component\HttpFoundation\Request;

/**
 * Fixture builders shared by A2cnDiscoveryControllerTest. Split out to keep
 * that class's own method count under the lint gate — these build test data,
 * they do not make assertions.
 */
final class A2cnDiscoveryControllerFixtures
{
    private function __construct() {}

    public static function request(): Request
    {
        return Request::create('https://shop.example/.well-known/a2cn-agent');
    }

    public static function settingsWithAPolicy(): QuoteAgentSettingsSource
    {
        return new class implements QuoteAgentSettingsSource {
            public function forSalesChannel(?string $salesChannelId): ?QuoteAgentSettings
            {
                return new QuoteAgentSettings(
                    policy: new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 15.0)),
                    rulesOnly: true,
                    llm: null,
                    strategyPrompt: null,
                );
            }
        };
    }

    /** @return array<string, mixed> */
    public static function decode(string|false $content): array
    {
        \assert(\is_string($content), description: 'A JsonResponse always has string content.');
        $decoded = json_decode($content, associative: true);
        \assert(\is_array($decoded), description: 'Every discovery/mandate response body is a JSON object.');

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
