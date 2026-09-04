<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Did;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use MerchantQuoteAgentPlugin\Protocol\Did\DidWebResolver;
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

    /** @param list<Response> $responses */
    private static function resolver(array $responses): DidWebResolver
    {
        return new DidWebResolver(
            new Client(['handler' => HandlerStack::create(new MockHandler($responses))]),
            new DefaultSigningKeyManager(),
            new NullLogger(),
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
