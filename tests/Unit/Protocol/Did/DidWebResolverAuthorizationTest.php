<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Did;

use MerchantQuoteAgentPlugin\Protocol\Did\DidWebResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Ucp\Sdk\Internal\Security\DefaultSigningKeyManager;

/**
 * Split out of DidWebResolverTest (mago's per-class method-count ceiling):
 * a key listed under `verificationMethod` is not automatically trusted to
 * sign — it must also be authorized under `assertionMethod` or
 * `authentication`, per DidWebJwk::pick(). A `keyAgreement`-only key (meant
 * for encryption key exchange, never signing) must be refused exactly as an
 * absent document is.
 */
final class DidWebResolverAuthorizationTest extends TestCase
{
    private const METHOD = 'did:web:buyer.example#key-1';

    public function testItReturnsNullForAMethodListedOnlyUnderKeyAgreement(): void
    {
        $resolver = self::resolver([new MockResponse(self::document(['keyAgreement' => [self::METHOD]]))]);

        self::assertNull($resolver->publicKeyPemFor(self::METHOD));
    }

    /** `authentication` authorizes a signing key exactly as `assertionMethod` does. */
    public function testItAcceptsAMethodAuthorizedOnlyUnderAuthentication(): void
    {
        $resolver = self::resolver([new MockResponse(self::document(['authentication' => [self::METHOD]]))]);

        self::assertIsString($resolver->publicKeyPemFor(self::METHOD));
    }

    public function testItReturnsNullWhenNeitherAssertionMethodNorAuthenticationListsIt(): void
    {
        $resolver = self::resolver([new MockResponse(self::document([]))]);

        self::assertNull($resolver->publicKeyPemFor(self::METHOD));
    }

    /**
     * `buyer.example` (RFC 2606) does not resolve on a real network, so the
     * safety gate's DNS lookup is stubbed the same way DidWebResolverTest
     * stubs it.
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

    /** @param array<string, mixed> $extra */
    private static function document(array $extra): string
    {
        return json_encode(
            [
                '@context' => ['https://www.w3.org/ns/did/v1'],
                'id' => 'did:web:buyer.example',
                'verificationMethod' => [[
                    'id' => self::METHOD,
                    'type' => 'JsonWebKey2020',
                    'controller' => 'did:web:buyer.example',
                    'publicKeyJwk' => [
                        'kty' => 'EC',
                        'crv' => 'P-256',
                        'x' => 'f83OJ3D2xF1Bg8vub9tLe1gHMzV76e8Tus9uPHvRVEU',
                        'y' => 'x_FEzRu9m36HLN_tue659LNpXW6pCyStikYjKIWI5a0',
                    ],
                ]],
            ] + $extra,
            \JSON_THROW_ON_ERROR,
        );
    }
}
