<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Http;

use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsSource;
use MerchantQuoteAgentPlugin\Protocol\Crypto\CompactJws;
use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentity;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentityResolver;
use MerchantQuoteAgentPlugin\Protocol\Identity\MissingSigningKey;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Security\DefaultJsonCanonicalization;
use Ucp\Sdk\Model\Security\PublicSigningKey;

final class A2cnDiscoveryControllerTest extends TestCase
{
    /**
     * All twelve fields the spec's "Documents" section enumerates.
     * `verification_method` is the one a buyer agent needs in order to pick a
     * key without parsing the DID document, and the README lists it too, so
     * publishing seven of the twelve made both wrong.
     */
    public function testTheDiscoveryDocumentCarriesAllTwelveFields(): void
    {
        $response = A2cnDiscoveryControllerFixtures::controller()->discovery(
            A2cnDiscoveryControllerFixtures::request(),
        );

        $body = A2cnDiscoveryControllerFixtures::decode($response->getContent());
        self::assertSame('0.2', $body['a2cn_version']);
        self::assertSame('merchant-quote-agent', $body['agent_id']);
        self::assertSame('did:web:shop.example', $body['did']);
        self::assertSame('did:web:shop.example#key-1', $body['verification_method']);
        self::assertSame(['declared'], $body['mandate_methods']);
        self::assertSame(['goods_procurement'], $body['authorized_deal_types']);
        self::assertSame('acts', $body['conformance_level']);
        self::assertSame(['name' => 'Example Shop'], $body['organization']);
        self::assertSame('https://shop.example', $body['endpoint']);
        self::assertSame('https://shop.example/.well-known/a2cn-seller-mandate', $body['mandate_url']);
        self::assertSame('https://shop.example/a2cn/records/{session_id}', $body['records_url']);
        self::assertMatchesRegularExpression(
            '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/',
            (string) $body['updated_at'],
            'updated_at must be the UTC `Z`-suffixed timestamp the rest of the module writes, per ProtocolTimestamp',
        );

        self::assertCount(12, $body, 'the document publishes exactly the enumerated fields');
    }

    /**
     * Regression for the live-shop finding: Symfony's Request::getHost()
     * always strips the port, but SalesChannelHostReader (which builds the
     * identity SellerActFactory signs acts under) keeps a non-default one —
     * so the discovery controller MUST resolve identity through
     * getHttpHost(), or the published DID document names a different DID
     * than the one this installation's acts are actually signed under, and
     * a conformant counterparty rejects the document on fetch.
     */
    public function testTheIdentityHostMatchesSalesChannelHostReadersPortConvention(): void
    {
        $controller = A2cnDiscoveryControllerFixtures::controller();

        $onPort = A2cnDiscoveryControllerFixtures::requestOnPort(8095);
        $discoveryOnPort = A2cnDiscoveryControllerFixtures::decode($controller->discovery($onPort)->getContent());
        $didOnPort = A2cnDiscoveryControllerFixtures::decode($controller->didDocument($onPort)->getContent());
        self::assertSame('did:web:shop.example%3A8095', $discoveryOnPort['did']);
        self::assertSame('did:web:shop.example%3A8095', $didOnPort['id']);

        $onDefaultPort = A2cnDiscoveryControllerFixtures::requestOnPort(443);
        $discoveryDefault = A2cnDiscoveryControllerFixtures::decode(
            $controller->discovery($onDefaultPort)->getContent(),
        );
        $didDefault = A2cnDiscoveryControllerFixtures::decode($controller->didDocument($onDefaultPort)->getContent());
        self::assertSame('did:web:shop.example', $discoveryDefault['did']);
        self::assertSame('did:web:shop.example', $didDefault['id']);
    }

    public function testTheDiscoveryAndDidResponsesAreCachedNotNoStore(): void
    {
        $controller = A2cnDiscoveryControllerFixtures::controller();
        $discovery = $controller->discovery(A2cnDiscoveryControllerFixtures::request());
        $did = $controller->didDocument(A2cnDiscoveryControllerFixtures::request());

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
        $response = A2cnDiscoveryControllerFixtures::controller()->didDocument(
            A2cnDiscoveryControllerFixtures::request(),
        );

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
        $response = A2cnDiscoveryControllerFixtures::controller()->didDocument(
            A2cnDiscoveryControllerFixtures::request(),
        );

        $body = A2cnDiscoveryControllerFixtures::decode($response->getContent());
        $jwk = $body['verificationMethod'][0]['publicKeyJwk'];
        self::assertArrayNotHasKey('d', $jwk);
    }

    public function testTheMandateIsSignedAndCached(): void
    {
        $response = A2cnDiscoveryControllerFixtures::controller()->mandate(A2cnDiscoveryControllerFixtures::request());

        self::assertSame(200, $response->getStatusCode());
        $body = A2cnDiscoveryControllerFixtures::decode($response->getContent());
        self::assertSame('declared', $body['mandate_type']);
        self::assertArrayHasKey('proof', $body);
        self::assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
    }

    /**
     * Spec acceptance criterion 5, verbatim: "verifies using only published
     * material." MandateSignerTest verifies against an in-memory test PEM;
     * this instead fetches the published did.json THROUGH the controller,
     * converts publicKeyJwk with the SDK's own PublicSigningKey::fromJwk()
     * (exactly what a real counterparty would do), and verifies the
     * mandate's proof against that — not a PEM the test already knows. The
     * negative half proves the check is not a tautology: a one-byte change
     * to the mandate body must break verification against that same key.
     */
    public function testTheMandateVerifiesAgainstOnlyThePublishedDidDocument(): void
    {
        $controller = A2cnDiscoveryControllerFixtures::controller();
        $request = A2cnDiscoveryControllerFixtures::request();

        $did = A2cnDiscoveryControllerFixtures::decode($controller->didDocument($request)->getContent());
        $mandate = A2cnDiscoveryControllerFixtures::decode($controller->mandate($request)->getContent());

        $publicKey = PublicSigningKey::fromJwk($did['verificationMethod'][0]['publicKeyJwk']);
        self::assertNotNull($publicKey->publicKeyPem);

        $hash = new ProtocolHash(new DefaultJsonCanonicalization());
        $verified = CompactJws::verify($mandate['proof']['jws'], $publicKey->publicKeyPem);

        $withoutProof = $mandate;
        unset($withoutProof['proof']);
        self::assertSame(
            $hash->of($withoutProof),
            $verified,
            'the mandate did not verify against the key the shop itself publishes',
        );

        $tampered = $withoutProof;
        $tampered['agent_id'] = 'a-different-agent';
        self::assertNotSame($hash->of($tampered), $verified, 'a tampered mandate body must not still verify');
    }

    public function testAMissingSigningKeyYields503RatherThanAStackTrace(): void
    {
        $controller = A2cnDiscoveryControllerFixtures::controller(identities: new class extends A2cnIdentityResolver {
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
        $controller = A2cnDiscoveryControllerFixtures::controller(settings: new class implements
            QuoteAgentSettingsSource {
            public function forSalesChannel(?string $salesChannelId): ?QuoteAgentSettings
            {
                return null;
            }
        });

        $response = $controller->mandate(A2cnDiscoveryControllerFixtures::request());

        self::assertSame(404, $response->getStatusCode());
    }
}
