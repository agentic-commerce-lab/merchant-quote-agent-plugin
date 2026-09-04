<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Http;

use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsSource;
use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use MerchantQuoteAgentPlugin\Protocol\Http\A2cnDiscoveryController;
use MerchantQuoteAgentPlugin\Protocol\Http\MandateDocumentResponder;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentity;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentityResolver;
use MerchantQuoteAgentPlugin\Protocol\Identity\MissingSigningKey;
use MerchantQuoteAgentPlugin\Protocol\Mandate\MandateSigner;
use MerchantQuoteAgentPlugin\Protocol\Mandate\SellerMandateFactory;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\TestActSigner;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Security\DefaultJsonCanonicalization;

final class A2cnDiscoveryControllerTest extends TestCase
{
    public function testTheDiscoveryDocumentCarriesTheRequiredFields(): void
    {
        $response = self::controller()->discovery(A2cnDiscoveryControllerFixtures::request());

        $body = A2cnDiscoveryControllerFixtures::decode($response->getContent());
        self::assertSame('0.2', $body['a2cn_version']);
        self::assertSame(['declared'], $body['mandate_methods']);
        self::assertSame('acts', $body['conformance_level']);
        self::assertSame('https://shop.example/.well-known/a2cn-seller-mandate', $body['mandate_url']);
        self::assertArrayHasKey('records_url', $body);
    }

    public function testTheDiscoveryAndDidResponsesAreCachedNotNoStore(): void
    {
        $discovery = self::controller()->discovery(A2cnDiscoveryControllerFixtures::request());
        $did = self::controller()->didDocument(A2cnDiscoveryControllerFixtures::request());

        // Symfony's ResponseHeaderBag reorders and re-serializes Cache-Control
        // directives (ResponseHeaderBag::computeCacheControlValue() /
        // getCacheControlHeader() ksort the parsed directives), so a literal
        // "public, max-age=300" is not what actually reaches the wire —
        // asserting both directives are present is what survives that
        // reordering instead of fighting it.
        foreach ([$discovery, $did] as $response) {
            $cacheControl = (string) $response->headers->get('Cache-Control');
            self::assertStringContainsString('public', $cacheControl);
            self::assertStringContainsString('max-age=300', $cacheControl);
        }
    }

    public function testTheDidDocumentListsExactlyOneJsonWebKeyMethod(): void
    {
        $response = self::controller()->didDocument(A2cnDiscoveryControllerFixtures::request());

        $body = A2cnDiscoveryControllerFixtures::decode($response->getContent());
        self::assertCount(1, $body['verificationMethod']);
        $method = $body['verificationMethod'][0];
        self::assertSame('did:web:shop.example#key-1', $method['id']);
        self::assertSame('JsonWebKey2020', $method['type']);
        self::assertSame([$method['id']], $body['authentication']);
        self::assertSame([$method['id']], $body['assertionMethod']);
    }

    public function testThePublishedJwkCarriesNoPrivateMember(): void
    {
        $response = self::controller()->didDocument(A2cnDiscoveryControllerFixtures::request());

        $body = A2cnDiscoveryControllerFixtures::decode($response->getContent());
        $jwk = $body['verificationMethod'][0]['publicKeyJwk'];
        self::assertArrayNotHasKey('d', $jwk);
    }

    public function testTheMandateIsSignedAndCached(): void
    {
        $response = self::controller()->mandate(A2cnDiscoveryControllerFixtures::request());

        self::assertSame(200, $response->getStatusCode());
        $body = A2cnDiscoveryControllerFixtures::decode($response->getContent());
        self::assertSame('declared', $body['mandate_type']);
        self::assertArrayHasKey('proof', $body);
        self::assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
    }

    public function testAMissingSigningKeyYields503RatherThanAStackTrace(): void
    {
        $controller = self::controller(identities: new class extends A2cnIdentityResolver {
            public function __construct() {}

            public function forHost(string $host, ?string $salesChannelId = null): A2cnIdentity
            {
                throw new MissingSigningKey('no key configured');
            }
        });

        $response = $controller->discovery(A2cnDiscoveryControllerFixtures::request());

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(
            'signing_key_missing',
            A2cnDiscoveryControllerFixtures::decode($response->getContent())['status'],
        );
    }

    public function testAMissingPolicyIsReportedAsNotFoundRatherThanAServerError(): void
    {
        $controller = self::controller(settings: new class implements QuoteAgentSettingsSource {
            public function forSalesChannel(?string $salesChannelId): ?QuoteAgentSettings
            {
                return null;
            }
        });

        $response = $controller->mandate(A2cnDiscoveryControllerFixtures::request());

        self::assertSame(404, $response->getStatusCode());
    }

    private static function controller(
        ?A2cnIdentityResolver $identities = null,
        ?QuoteAgentSettingsSource $settings = null,
    ): A2cnDiscoveryController {
        $keys = TestActSigner::keyStore();
        $hash = new ProtocolHash(new DefaultJsonCanonicalization());

        $mandateDocument = new MandateDocumentResponder(
            $settings ?? A2cnDiscoveryControllerFixtures::settingsWithAPolicy(),
            new SellerMandateFactory(),
            new MandateSigner($hash, $keys),
        );

        return new A2cnDiscoveryController($identities ?? TestActSigner::identities(), $keys, $mandateDocument);
    }
}
