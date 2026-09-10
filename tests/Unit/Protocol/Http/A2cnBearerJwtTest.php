<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Http;

use MerchantQuoteAgentPlugin\Protocol\Crypto\Base64Url;
use MerchantQuoteAgentPlugin\Protocol\Crypto\Es256Signature;
use MerchantQuoteAgentPlugin\Protocol\Did\DidWebResolver;
use MerchantQuoteAgentPlugin\Protocol\Http\A2cnBearerJwt;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\TestActSigner;
use PHPUnit\Framework\TestCase;

final class A2cnBearerJwtTest extends TestCase
{
    private const BUYER = 'did:web:buyer.example';
    private const METHOD = self::BUYER . '#key-1';
    private const SELLER = 'did:web:shop.example';

    public function testItReturnsTheIssuerOfAValidToken(): void
    {
        self::assertSame(self::BUYER, $this->verify($this->token()));
    }

    public function testItRefusesAWrongAudience(): void
    {
        self::assertNull($this->verify($this->token(audience: 'did:web:someone.else')));
    }

    public function testItRefusesAnExpiredToken(): void
    {
        self::assertNull($this->verify($this->token(exp: 1_600_000_000)));
    }

    public function testItRefusesAlgNone(): void
    {
        $header = Base64Url::encode('{"alg":"none","kid":"' . self::METHOD . '"}');
        $claims = Base64Url::encode((string) json_encode([
            'iss' => self::BUYER,
            'aud' => self::SELLER,
            'exp' => 4_000_000_000,
        ]));

        self::assertNull($this->verify($header . '.' . $claims . '.'));
    }

    public function testItRefusesATokenWithNoIssuer(): void
    {
        self::assertNull($this->verify($this->token(issuer: '')));
    }

    public function testItRefusesAMissingBearerPrefix(): void
    {
        self::assertNull((new A2cnBearerJwt($this->resolver()))->issuerOf(
            $this->token(),
            self::SELLER,
            new \DateTimeImmutable('2026-09-10T10:00:00+00:00'),
        ));
    }

    public function testItRefusesAnUnresolvableKid(): void
    {
        self::assertNull((new A2cnBearerJwt(ProtocolFixtures::resolvingTo(null)))->issuerOf(
            'Bearer ' . $this->token(),
            self::SELLER,
            new \DateTimeImmutable('2026-09-10T10:00:00+00:00'),
        ));
    }

    private function verify(#[\SensitiveParameter] string $token): ?string
    {
        return (new A2cnBearerJwt($this->resolver()))->issuerOf(
            'Bearer ' . $token,
            self::SELLER,
            new \DateTimeImmutable('2026-09-10T10:00:00+00:00'),
        );
    }

    private function token(
        string $issuer = self::BUYER,
        string $audience = self::SELLER,
        int $exp = 4_000_000_000,
    ): string {
        $header = Base64Url::encode('{"alg":"ES256","typ":"JWT","kid":"' . self::METHOD . '"}');
        $claims = Base64Url::encode((string) json_encode([
            'iss' => $issuer,
            'aud' => $audience,
            'exp' => $exp,
            'jti' => 'token-1',
        ]));

        $der = '';
        openssl_sign($header . '.' . $claims, $der, TestActSigner::key()->privateKeyPem, \OPENSSL_ALGO_SHA256);

        return $header . '.' . $claims . '.' . Base64Url::encode(Es256Signature::toRaw($der));
    }

    private function resolver(): DidWebResolver
    {
        // A private const of this test class is not visible from an
        // anonymous class body, even one declared right here — so the
        // expected method name is passed in rather than read via self::.
        return new class(self::METHOD) extends DidWebResolver {
            public function __construct(
                private readonly string $expectedMethod,
            ) {}

            public function publicKeyPemFor(string $verificationMethod): ?string
            {
                return $verificationMethod === $this->expectedMethod ? TestActSigner::publicKeyPem() : null;
            }
        };
    }
}
