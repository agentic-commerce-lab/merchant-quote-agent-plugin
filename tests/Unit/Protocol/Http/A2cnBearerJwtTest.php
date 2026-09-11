<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Http;

use MerchantQuoteAgentPlugin\Protocol\Crypto\Base64Url;
use MerchantQuoteAgentPlugin\Protocol\Crypto\Es256Signature;
use MerchantQuoteAgentPlugin\Protocol\Did\DidWebResolver;
use MerchantQuoteAgentPlugin\Protocol\Http\A2cnBearerJwt;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\TestActSigner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class A2cnBearerJwtTest extends TestCase
{
    private const BUYER = 'did:web:buyer.example';
    private const METHOD = self::BUYER . '#key-1';
    private const SELLER = 'did:web:shop.example';
    private const MALLORY_METHOD = 'did:web:mallory.example#key-1';

    /** @var ?array{private: string, public: string} */
    private static ?array $malloryKeyPair = null;

    public function testItReturnsTheIssuerOfAValidToken(): void
    {
        self::assertSame(self::BUYER, $this->verify(self::token()));
    }

    public function testItRefusesAMissingBearerPrefix(): void
    {
        self::assertNull((new A2cnBearerJwt($this->resolver()))->issuerOf(
            self::token(),
            self::SELLER,
            new \DateTimeImmutable('2026-09-10T10:00:00+00:00'),
        ));
    }

    public function testItRefusesAnUnresolvableKid(): void
    {
        self::assertNull((new A2cnBearerJwt(ProtocolFixtures::resolvingTo(null)))->issuerOf(
            'Bearer ' . self::token(),
            self::SELLER,
            new \DateTimeImmutable('2026-09-10T10:00:00+00:00'),
        ));
    }

    /**
     * Every one of these is a token that must be refused, but for a
     * different reason — including two (`claims tampered after signing`,
     * `signed by the wrong key`) that pass every header and claims gate and
     * are refused ONLY by signatureIsGood(). Those two matter more than they
     * look: every other case here is refused before signature verification
     * ever runs, so without them a broken `openssl_verify(...) === 1` check
     * (e.g. a stray `return true`) would leave this whole test class green.
     * Confirmed by temporarily mutating signatureIsGood() to `return true`
     * and watching exactly those two fail, nothing else.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function malformedTokens(): iterable
    {
        yield 'wrong audience' => [self::token(audience: 'did:web:someone.else')];
        yield 'expired' => [self::token(exp: 1_600_000_000)];
        yield 'no issuer' => [self::token(issuer: '')];
        yield 'malformed segment count' => ['not-a-jwt'];
        yield 'missing expiry' => [self::tokenWithClaims(['iss' => self::BUYER, 'aud' => self::SELLER])];
        yield 'non-integer expiry' => [self::tokenWithClaims([
            'iss' => self::BUYER,
            'aud' => self::SELLER,
            'exp' => '4000000000',
        ])];
        yield 'alg none' => [
            Base64Url::encode('{"alg":"none","kid":"' . self::METHOD . '"}')
                . '.'
                . Base64Url::encode((string) json_encode([
                    'iss' => self::BUYER,
                    'aud' => self::SELLER,
                    'exp' => 4_000_000_000,
                ]))
                . '.',
        ];
        yield 'signed by the wrong key' => [self::token(privateKeyPem: ProtocolFixtures::keyPair()['private'])];
        yield 'claims tampered after signing' => [self::tokenWithTamperedClaims()];

        // Otherwise perfect: correct aud, live exp, a kid that resolves and
        // was genuinely signed with the key it names — Mallory's own. It can
        // only be refused by the kid-controlled-by-iss check, because
        // everything else about it verifies.
        yield 'kid names a verification method under a DIFFERENT DID than iss claims' => [
            self::token(
                privateKeyPem: (self::$malloryKeyPair ??= ProtocolFixtures::keyPair())['private'],
                kid: self::MALLORY_METHOD,
            ),
        ];
    }

    #[DataProvider('malformedTokens')]
    public function testItRefusesAMalformedOrUnauthenticatedToken(#[\SensitiveParameter] string $token): void
    {
        self::assertNull($this->verify($token));
    }

    private function verify(#[\SensitiveParameter] string $token): ?string
    {
        return (new A2cnBearerJwt($this->resolver()))->issuerOf(
            'Bearer ' . $token,
            self::SELLER,
            new \DateTimeImmutable('2026-09-10T10:00:00+00:00'),
        );
    }

    private static function token(
        string $issuer = self::BUYER,
        string $audience = self::SELLER,
        int $exp = 4_000_000_000,
        #[\SensitiveParameter]
        ?string $privateKeyPem = null,
        string $kid = self::METHOD,
    ): string {
        return self::tokenWithClaims(
            [
                'iss' => $issuer,
                'aud' => $audience,
                'exp' => $exp,
                'jti' => 'token-1',
            ],
            $privateKeyPem,
            $kid,
        );
    }

    /** A valid token whose claims segment was swapped after signing: same shape, different signed bytes. */
    private static function tokenWithTamperedClaims(): string
    {
        [$header, , $signature] = explode('.', self::token());
        $tamperedClaims = Base64Url::encode((string) json_encode([
            'iss' => self::BUYER,
            'aud' => self::SELLER,
            'exp' => 4_000_000_000,
            'jti' => 'a-different-message',
        ]));

        return $header . '.' . $tamperedClaims . '.' . $signature;
    }

    /** @param array<string, mixed> $claims */
    private static function tokenWithClaims(
        array $claims,
        #[\SensitiveParameter]
        ?string $privateKeyPem = null,
        string $kid = self::METHOD,
    ): string {
        $header = Base64Url::encode('{"alg":"ES256","typ":"JWT","kid":"' . $kid . '"}');
        $encodedClaims = Base64Url::encode((string) json_encode($claims));

        $der = '';
        openssl_sign(
            $header . '.' . $encodedClaims,
            $der,
            $privateKeyPem ?? TestActSigner::key()->privateKeyPem,
            \OPENSSL_ALGO_SHA256,
        );

        return $header . '.' . $encodedClaims . '.' . Base64Url::encode(Es256Signature::toRaw($der));
    }

    private function resolver(): DidWebResolver
    {
        // A private const of this test class is not visible from an
        // anonymous class body, even one declared right here — so the
        // expected method names are passed in rather than read via self::.
        // Resolving Mallory's kid to a real key she controls is what makes
        // the kid-under-a-different-DID case discriminating: the token must
        // be genuinely, verifiably signed by the key its own kid names, so
        // only the new iss/kid binding check can refuse it.
        $malloryPem = (self::$malloryKeyPair ??= ProtocolFixtures::keyPair())['public'];

        return new class(self::METHOD, self::MALLORY_METHOD, $malloryPem) extends DidWebResolver {
            public function __construct(
                private readonly string $expectedMethod,
                private readonly string $malloryMethod,
                private readonly string $malloryPem,
            ) {}

            public function publicKeyPemFor(string $verificationMethod): ?string
            {
                return match ($verificationMethod) {
                    $this->expectedMethod => TestActSigner::publicKeyPem(),
                    $this->malloryMethod => $this->malloryPem,
                    default => null,
                };
            }
        };
    }
}
