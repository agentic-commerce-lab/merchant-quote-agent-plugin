<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Crypto;

use MerchantQuoteAgentPlugin\Protocol\Crypto\Base64Url;
use MerchantQuoteAgentPlugin\Protocol\Crypto\CompactJws;
use MerchantQuoteAgentPlugin\Protocol\Crypto\Es256Signature;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Model\Security\PublicSigningKey;

final class CompactJwsTest extends TestCase
{
    /**
     * RFC 7515 Appendix A.3 — the published ES256 example. ECDSA signing is
     * non-deterministic, so a fixed vector can only pin VERIFICATION; that is
     * also the direction a counterparty exercises against us.
     */
    private const RFC7515_A3_JWS =
        'eyJhbGciOiJFUzI1NiJ9.'
            . 'eyJpc3MiOiJqb2UiLA0KICJleHAiOjEzMDA4MTkzODAsDQogImh0dHA6Ly9leGFtcGxlLmNvbS9pc19yb290Ijp0cnVlfQ.'
            . 'DtEhU3ljbEg8L38VWAfUAqOyKAM6-Xx-F4GawxaepmXFCgfTjDxw5djxLa8ISlSApmWQxfKTUJqPP3-Kg6NU1Q';

    private const RFC7515_A3_JWK = [
        'kid' => 'rfc7515-a3',
        'kty' => 'EC',
        'alg' => 'ES256',
        'use' => 'sig',
        'crv' => 'P-256',
        'x' => 'f83OJ3D2xF1Bg8vub9tLe1gHMzV76e8Tus9uPHvRVEU',
        'y' => 'x_FEzRu9m36HLN_tue659LNpXW6pCyStikYjKIWI5a0',
    ];

    public function testItVerifiesTheRfc7515Es256Vector(): void
    {
        $pem = PublicSigningKey::fromJwk(self::RFC7515_A3_JWK)->publicKeyPem;
        self::assertIsString($pem);

        $payload = CompactJws::verify(self::RFC7515_A3_JWS, $pem);

        self::assertIsString($payload);
        self::assertStringContainsString('"iss":"joe"', $payload);
    }

    public function testItRejectsATamperedPayload(): void
    {
        $pem = PublicSigningKey::fromJwk(self::RFC7515_A3_JWK)->publicKeyPem;
        self::assertIsString($pem);

        [$header, $body, $signature] = explode('.', self::RFC7515_A3_JWS);
        $tampered = $header . '.' . $body . 'x.' . $signature;

        self::assertNull(CompactJws::verify($tampered, $pem));
    }

    /**
     * Only ES256 is accepted — not `none`, not another algorithm, not a
     * missing `alg`, and not a header carrying a member beyond `alg`/`kid`
     * (`typ` included: see CompactJws::HEADER_MEMBERS). Guessing at any other
     * header would be the classic JWS algorithm-confusion bug.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function rejectedHeaderProvider(): iterable
    {
        yield 'alg none' => ['{"alg":"none"}'];
        yield 'another alg' => ['{"alg":"HS256"}'];
        yield 'missing alg' => ['{"kid":"did:web:buyer.example#key-1"}'];
        yield 'empty header' => ['{}'];
        yield 'unmodelled member' => ['{"alg":"ES256","extra":true}'];
        yield 'typ' => ['{"alg":"ES256","typ":"JWT"}'];
        yield 'not json' => ['not-json-at-all'];
        yield 'json but not an object' => ['"ES256"'];
    }

    #[DataProvider('rejectedHeaderProvider')]
    public function testItRejectsAHeaderThatIsNotEs256(string $header): void
    {
        ['private' => $private, 'public' => $public] = self::keyPair();
        [, $body, $signature] = explode('.', CompactJws::sign('payload', $private));

        $tampered = Base64Url::encode($header) . '.' . $body . '.' . $signature;

        self::assertNull(CompactJws::verify($tampered, $public), $header);
    }

    /**
     * The counterparty's reference implementation puts its verification method
     * in the header `kid` and checks only `alg` on the way back. A verifier
     * that demanded a bare header would refuse every act they sign, so both
     * shapes must round trip — and the kid-bearing header must be
     * deterministic, since its bytes are part of the signing input.
     */
    public function testItRoundTripsWithAndWithoutAKidInTheHeader(): void
    {
        ['private' => $private, 'public' => $public] = self::keyPair();

        $withKid = CompactJws::sign('payload', $private, 'did:web:shop.example#k1');
        $withoutKid = CompactJws::sign('payload', $private);

        self::assertSame('payload', CompactJws::verify($withKid, $public));
        self::assertSame('payload', CompactJws::verify($withoutKid, $public));

        // Sorted keys, no spaces, unescaped slashes.
        self::assertSame(
            '{"alg":"ES256","kid":"did:web:shop.example#k1"}',
            Base64Url::decode(explode('.', $withKid)[0]),
        );
        self::assertSame('{"alg":"ES256"}', Base64Url::decode(explode('.', $withoutKid)[0]));
    }

    /**
     * `Es256Signature::toDer()` throws on anything that is not exactly 64
     * bytes, and `Base64Url::decode()` fails closed to '' on input that is not
     * valid base64url — both must come back as a null verification, not an
     * uncaught exception.
     */
    public function testItRejectsAMalformedSignatureSegment(): void
    {
        ['private' => $private, 'public' => $public] = self::keyPair();
        $jws = CompactJws::sign('payload', $private);
        [$header, $body] = explode('.', $jws);

        $wrongLength = $header . '.' . $body . '.' . Base64Url::encode('too-short');
        $notBase64Url = $header . '.' . $body . '.' . '***not-base64url***';

        self::assertNull(CompactJws::verify($wrongLength, $public));
        self::assertNull(CompactJws::verify($notBase64Url, $public));
    }

    public function testItRoundTripsAStringPayload(): void
    {
        ['private' => $private, 'public' => $public] = self::keyPair();

        // The A2CN payload is the act hash as an ASCII string, NOT a claim set.
        $jws = CompactJws::sign('URfXIwylDk4_weTXPY5hoEPnFvUdKgGCZZ2g39kTamI', $private);

        self::assertSame(3, \count(explode('.', $jws)));
        self::assertSame('URfXIwylDk4_weTXPY5hoEPnFvUdKgGCZZ2g39kTamI', CompactJws::verify($jws, $public));
    }

    public function testItSignsWithARawSixtyFourByteSignature(): void
    {
        ['private' => $private] = self::keyPair();

        $signature = Base64Url::decode(explode('.', CompactJws::sign('payload', $private))[2]);

        // JWS ES256 is raw R||S, not DER — 64 bytes for P-256, always.
        self::assertSame(64, \strlen($signature));
    }

    public function testItRoundTripsDerAndRawSignatures(): void
    {
        ['private' => $private] = self::keyPair();
        $der = '';
        self::assertNotFalse(openssl_sign('base', $der, $private, \OPENSSL_ALGO_SHA256));

        $raw = Es256Signature::toRaw($der);

        self::assertSame(64, \strlen($raw));
        self::assertSame($der, Es256Signature::toDer($raw));
    }

    /** @return array{private: string, public: string} */
    private static function keyPair(): array
    {
        $resource = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($resource);
        $private = '';
        self::assertTrue(openssl_pkey_export($resource, $private));
        $details = openssl_pkey_get_details($resource);
        self::assertIsArray($details);
        self::assertIsString($details['key']);

        return ['private' => $private, 'public' => $details['key']];
    }
}
