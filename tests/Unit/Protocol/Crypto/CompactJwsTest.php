<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Crypto;

use MerchantQuoteAgentPlugin\Protocol\Crypto\Base64Url;
use MerchantQuoteAgentPlugin\Protocol\Crypto\CompactJws;
use MerchantQuoteAgentPlugin\Protocol\Crypto\Es256Signature;
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

    public function testItRejectsATamperedSignature(): void
    {
        $pem = PublicSigningKey::fromJwk(self::RFC7515_A3_JWK)->publicKeyPem;
        self::assertIsString($pem);

        [$header, $body, $signature] = explode('.', self::RFC7515_A3_JWS);
        $tampered = $header . '.' . $body . 'x.' . $signature;

        self::assertNull(CompactJws::verify($tampered, $pem));
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
