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
 * The protected header carries `alg` and, when the caller names one, the
 * signer's `kid` — the verification method the counterparty resolves. Real
 * A2CN implementations put it there, so a verifier that demanded a bare
 * `{"alg":"ES256"}` would refuse every act they sign; `verify()` parses the
 * header instead of comparing bytes, and tolerates exactly `alg` and `kid`.
 *
 * `verify()` returns the payload or null. Null, not an exception: a
 * counterparty act that does not verify is evidence about them, handled as a
 * protocol violation, never an error in our own control flow.
 */
final class CompactJws
{
    private const ALG = 'ES256';

    /**
     * The only header members this accepts. Anything else — `crit`, `jku`,
     * an embedded `jwk`, even a harmless `typ` — changes what a verifier is
     * being asked to do, and we would rather refuse than interpret it.
     */
    private const HEADER_MEMBERS = ['alg', 'kid'];

    private function __construct() {}

    /** @throws MalformedSignature|\RuntimeException */
    public static function sign(string $payload, string $privateKeyPem, ?string $kid = null): string
    {
        $signingInput = Base64Url::encode(self::header($kid)) . '.' . Base64Url::encode($payload);
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
        if (!self::headerIsEs256(Base64Url::decode($header))) {
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

    /**
     * Sorted keys, no whitespace: `alg` sorts before `kid`, so a given kid
     * always yields the same protected-header bytes and the same signing
     * input.
     *
     * @throws \RuntimeException
     */
    private static function header(?string $kid): string
    {
        $header = $kid === null ? ['alg' => self::ALG] : ['alg' => self::ALG, 'kid' => $kid];

        // Slashes stay unescaped: a `did:web` verification method with a path
        // would otherwise be serialised as `\/`, which is legal JSON but not
        // what any other implementation writes.
        $encoded = json_encode($header, \JSON_UNESCAPED_SLASHES);
        if (!\is_string($encoded)) {
            throw new \RuntimeException('Unable to serialise the A2CN JWS protected header.');
        }

        return $encoded;
    }

    /**
     * Fails closed on anything that is not an ES256 header: another algorithm,
     * `none`, a missing `alg`, junk that is not JSON, or a member we do not
     * model. Guessing at any of those would be the classic JWS
     * algorithm-confusion bug.
     */
    private static function headerIsEs256(string $decoded): bool
    {
        $header = json_decode($decoded, associative: true);
        if (!\is_array($header) || ($header['alg'] ?? null) !== self::ALG) {
            return false;
        }

        return array_diff(array_keys($header), self::HEADER_MEMBERS) === [];
    }
}
