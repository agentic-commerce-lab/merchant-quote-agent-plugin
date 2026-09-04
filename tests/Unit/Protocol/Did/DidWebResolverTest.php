<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Did;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use MerchantQuoteAgentPlugin\Protocol\Did\DidWebResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ucp\Sdk\Internal\Security\DefaultSigningKeyManager;

final class DidWebResolverTest extends TestCase
{
    private const METHOD = 'did:web:buyer.example#key-1';

    public function testItReturnsThePemForTheNamedVerificationMethod(): void
    {
        $resolver = self::resolver([new Response(200, [], self::document())]);

        $pem = $resolver->publicKeyPemFor(self::METHOD);

        self::assertIsString($pem);
        self::assertStringContainsString('BEGIN PUBLIC KEY', $pem);
    }

    public function testItMemoizesSoOneChainCostsOneRequest(): void
    {
        $handler = new MockHandler([new Response(200, [], self::document())]);
        $resolver = new DidWebResolver(
            new Client(['handler' => HandlerStack::create($handler)]),
            new DefaultSigningKeyManager(),
            new NullLogger(),
            // buyer.example (RFC 2606) does not resolve on a real network, so
            // the safety gate's DNS lookup is stubbed — see resolver() below.
            static fn(string $host): array => $host === 'buyer.example' ? ['203.0.113.10'] : [],
        );

        $resolver->publicKeyPemFor(self::METHOD);
        $resolver->publicKeyPemFor(self::METHOD);

        // A second HTTP call would have thrown: the queue holds one response.
        self::assertCount(0, $handler);
    }

    public function testItReturnsNullWhenTheDocumentDoesNotListTheMethod(): void
    {
        $resolver = self::resolver([new Response(200, [], self::document(id: 'did:web:buyer.example#other'))]);

        self::assertNull($resolver->publicKeyPemFor(self::METHOD));
    }

    public function testItReturnsNullOnATransportFailure(): void
    {
        $resolver = self::resolver([new Response(404)]);

        self::assertNull($resolver->publicKeyPemFor(self::METHOD));
    }

    public function testItReturnsNullForAnUnsupportedCurve(): void
    {
        $resolver = self::resolver([new Response(200, [], self::document(curve: 'P-384'))]);

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
        $resolver = self::resolver([new Response(200, [], self::document(id: $method))]);

        self::assertNull($resolver->publicKeyPemFor($method));
    }

    /**
     * `buyer.example` (RFC 2606) does not resolve on a real network, so the
     * safety gate's DNS lookup is stubbed the same way the UCP SDK's own tests
     * stub it (see HttpAgentProfileFetcherTest) — a fake resolver mapping the
     * fixture host to a public-looking, non-reserved address, keeping these
     * tests hermetic.
     *
     * @param list<Response> $responses
     */
    private static function resolver(array $responses): DidWebResolver
    {
        return new DidWebResolver(
            new Client(['handler' => HandlerStack::create(new MockHandler($responses))]),
            new DefaultSigningKeyManager(),
            new NullLogger(),
            static fn(string $host): array => $host === 'buyer.example' ? ['203.0.113.10'] : [],
        );
    }

    private static function document(string $id = 'did:web:buyer.example#key-1', string $curve = 'P-256'): string
    {
        // A minimal DID document as a counterparty really publishes one: no
        // kid, no alg, no use — which is why the resolver normalizes.
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
            ],
            \JSON_THROW_ON_ERROR,
        );
    }
}
