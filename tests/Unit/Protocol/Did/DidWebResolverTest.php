<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Did;

use MerchantQuoteAgentPlugin\Protocol\Did\DidWebResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Ucp\Sdk\Internal\Security\DefaultSigningKeyManager;

final class DidWebResolverTest extends TestCase
{
    private const METHOD = 'did:web:buyer.example#key-1';

    public function testItReturnsThePemForTheNamedVerificationMethod(): void
    {
        $resolver = self::resolver([new MockResponse(self::document())]);

        $pem = $resolver->publicKeyPemFor(self::METHOD);

        self::assertIsString($pem);
        self::assertStringContainsString('BEGIN PUBLIC KEY', $pem);
    }

    public function testItMemoizesSoOneChainCostsOneRequest(): void
    {
        $client = new MockHttpClient([new MockResponse(self::document())]);
        $resolver = new DidWebResolver(
            $client,
            new DefaultSigningKeyManager(),
            new NullLogger(),
            // buyer.example (RFC 2606) does not resolve on a real network, so
            // the safety gate's DNS lookup is stubbed — see resolver() below.
            static fn(string $host): array => $host === 'buyer.example' ? ['203.0.113.10'] : [],
        );

        $resolver->publicKeyPemFor(self::METHOD);
        $resolver->publicKeyPemFor(self::METHOD);

        // A second HTTP call would have failed: the queue holds one response.
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testItReturnsNullWhenTheDocumentDoesNotListTheMethod(): void
    {
        $resolver = self::resolver([new MockResponse(self::document(id: 'did:web:buyer.example#other'))]);

        self::assertNull($resolver->publicKeyPemFor(self::METHOD));
    }

    public function testItReturnsNullOnATransportFailure(): void
    {
        $resolver = self::resolver([new MockResponse('', ['http_code' => 404])]);

        self::assertNull($resolver->publicKeyPemFor(self::METHOD));
    }

    public function testItReturnsNullForAnUnsupportedCurve(): void
    {
        $resolver = self::resolver([new MockResponse(self::document(curve: 'P-384'))]);

        self::assertNull($resolver->publicKeyPemFor(self::METHOD));
    }

    public function testItReturnsNullForANonDidWebMethod(): void
    {
        $resolver = self::resolver([]);

        self::assertNull($resolver->publicKeyPemFor('did:key:z6Mk#1'));
    }

    /**
     * A buyer act naming an internal or blocked host must never make this
     * shop's server issue that request. The mock queue holds a real, matching
     * document for each case — if the safety gate is missing, resolution
     * succeeds with a real PEM instead of null, so the test fails loudly
     * rather than by accident (an empty queue would also throw, but for the
     * wrong reason).
     *
     * @return iterable<string, array{0: string}>
     */
    public static function unsafeHostProvider(): iterable
    {
        // Cloud-metadata host UrlSafetyValidator blocks outright.
        yield 'blocked host' => ['did:web:169.254.169.254#key-1'];
        // A private-range address.
        yield 'private-range host' => ['did:web:10.0.0.5#key-1'];
        // Only 443 and 8443 are allowed; did:web's own port form must not bypass that.
        yield 'disallowed port' => ['did:web:buyer.example%3A8080#key-1'];
    }

    #[DataProvider('unsafeHostProvider')]
    public function testItReturnsNullForAnUnsafeUrl(string $method): void
    {
        $resolver = self::resolver([new MockResponse(self::document(id: $method))]);

        self::assertNull($resolver->publicKeyPemFor($method));
    }

    /**
     * `buyer.example` (RFC 2606) does not resolve on a real network, so the
     * safety gate's DNS lookup is stubbed the same way the UCP SDK's own tests
     * stub it (see HttpAgentProfileFetcherTest) — a fake resolver mapping the
     * fixture host to a public-looking, non-reserved address, keeping these
     * tests hermetic.
     *
     * @param list<MockResponse> $responses
     */
    private static function resolver(array $responses): DidWebResolver
    {
        return new DidWebResolver(
            new MockHttpClient($responses),
            new DefaultSigningKeyManager(),
            new NullLogger(),
            static fn(string $host): array => $host === 'buyer.example' ? ['203.0.113.10'] : [],
        );
    }

    /** @param list<string> $assertionMethod */
    private static function document(
        string $id = 'did:web:buyer.example#key-1',
        string $curve = 'P-256',
        array $assertionMethod = ['did:web:buyer.example#key-1'],
    ): string {
        // A minimal DID document as a counterparty really publishes one: no
        // kid, no alg, no use — which is why the resolver normalizes.
        // `assertionMethod` authorizes the key to sign, same as a real
        // did:web document must — see DidWebResolverAuthorizationTest for
        // the case where a key is listed under `verificationMethod` but not
        // authorized to sign anything.
        return json_encode(
            [
                '@context' => ['https://www.w3.org/ns/did/v1'],
                'id' => 'did:web:buyer.example',
                'verificationMethod' => [[
                    'id' => $id,
                    'type' => 'JsonWebKey2020',
                    'controller' => 'did:web:buyer.example',
                    'publicKeyJwk' => [
                        'kty' => 'EC',
                        'crv' => $curve,
                        'x' => 'f83OJ3D2xF1Bg8vub9tLe1gHMzV76e8Tus9uPHvRVEU',
                        'y' => 'x_FEzRu9m36HLN_tue659LNpXW6pCyStikYjKIWI5a0',
                    ],
                ]],
                'assertionMethod' => $assertionMethod,
            ],
            \JSON_THROW_ON_ERROR,
        );
    }
}
