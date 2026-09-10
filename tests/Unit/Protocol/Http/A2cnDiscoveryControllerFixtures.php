<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Http;

use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsSource;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use MerchantQuoteAgentPlugin\Protocol\Http\A2cnDiscoveryController;
use MerchantQuoteAgentPlugin\Protocol\Http\MandateDocumentResponder;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentityResolver;
use MerchantQuoteAgentPlugin\Protocol\Mandate\MandateSigner;
use MerchantQuoteAgentPlugin\Protocol\Mandate\SellerMandateFactory;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\TestActSigner;
use Symfony\Component\HttpFoundation\Request;
use Ucp\Sdk\Internal\Security\DefaultJsonCanonicalization;

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

    /** A request on a non-default port, for pinning the host the identity resolves against. */
    public static function requestOnPort(int $port): Request
    {
        return Request::create(\sprintf('https://shop.example:%d/.well-known/a2cn-agent', $port));
    }

    public static function settingsWithAPolicy(): QuoteAgentSettingsSource
    {
        return new class implements QuoteAgentSettingsSource {
            public function forSalesChannel(?string $salesChannelId): ?QuoteAgentSettings
            {
                return new QuoteAgentSettings(
                    policy: new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 15.0)),
                    llm: new ModelAccess('sk-test', 'https://api.example.com/v1', 'gpt-4o-mini'),
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

    /** @return array<string, mixed> */
    public static function discoveryDocument(): array
    {
        $response = self::controller()->discovery(self::request());

        return self::decode($response->getContent());
    }

    public static function controller(
        ?A2cnIdentityResolver $identities = null,
        ?QuoteAgentSettingsSource $settings = null,
    ): A2cnDiscoveryController {
        $keys = TestActSigner::keyStore();
        $hash = new ProtocolHash(new DefaultJsonCanonicalization());

        $mandateDocument = new MandateDocumentResponder(
            $settings ?? self::settingsWithAPolicy(),
            new SellerMandateFactory(),
            new MandateSigner($hash, $keys),
        );

        return new A2cnDiscoveryController($identities ?? TestActSigner::identities(), $keys, $mandateDocument);
    }
}
