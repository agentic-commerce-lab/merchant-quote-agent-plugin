<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Crypto;

/**
 * ES256 compact JWS over a STRING payload.
 *
 * A2CN signs the act hash itself (spec 7.4): JCS → base64url(SHA-256) → sign
 * that hash string as an attached compact JWS. So the payload segment is
 * `base64url(ASCII(hash))`, not a JSON claim set — which is why no JWT library
 * fits: they all insist on claims.
 *
 * `verify()` returns the payload or null. Null, not an exception: a
 * counterparty act that does not verify is evidence about them, handled as a
 * protocol violation, never an error in our own control flow.
 */
final class CompactJws
{
    private const HEADER = '{"alg":"ES256"}';

    private function __construct() {}

    /** @throws MalformedSignature|\RuntimeException */
    public static function sign(string $payload, string $privateKeyPem): string
    {
        $signingInput = Base64Url::encode(self::HEADER) . '.' . Base64Url::encode($payload);
        $der = '';
        if (!openssl_sign($signingInput, $der, $privateKeyPem, \OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Unable to sign the A2CN payload with the configured key.');
        }

        return $signingInput . '.' . Base64Url::encode(Es256Signature::toRaw($der));
    }

    public static function verify(string $jws, string $publicKeyPem): ?string
    {
        $segments = explode('.', $jws);
        if (\count($segments) !== 3) {
            return null;
        }

        [$header, $payload, $signature] = $segments;
        if (Base64Url::decode($header) !== self::HEADER) {
            // Only ES256 with a bare alg header is accepted. Anything else —
            // another algorithm, `none`, extra header fields — is not ours to
            // interpret, and guessing would be the classic JWS confusion bug.
            return null;
        }

        try {
            // The `count($segments) !== 3` guard above already proves $header,
            // $payload and $signature are all set; the analyzer cannot follow
            // that through the list-destructure, so it sees `string|null`.
            /** @mago-expect analysis:possibly-null-argument */
            $der = Es256Signature::toDer(Base64Url::decode($signature));
        } catch (MalformedSignature) {
            return null;
        }

        if (openssl_verify($header . '.' . $payload, $der, $publicKeyPem, \OPENSSL_ALGO_SHA256) !== 1) {
            return null;
        }

        /** @mago-expect analysis:possibly-null-argument */
        return Base64Url::decode($payload);
    }
}
