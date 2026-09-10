<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Http;

use MerchantQuoteAgentPlugin\Protocol\Crypto\Base64Url;
use MerchantQuoteAgentPlugin\Protocol\Crypto\Es256Signature;
use MerchantQuoteAgentPlugin\Protocol\Crypto\MalformedSignature;
use MerchantQuoteAgentPlugin\Protocol\Did\DidWebResolver;

/**
 * The A2CN transport token: an ES256 Bearer JWT whose `iss` is the sender's
 * DID and whose header `kid` names the verification method that signed it
 * (spec §12.1.4).
 *
 * Not CompactJws. That class signs and verifies a STRING payload and refuses
 * any protected-header member outside `alg` and `kid` — deliberately, because
 * an act signature that tolerated `crit` or an embedded `jwk` would be
 * interpreting an instruction rather than checking a signature. A real JWT
 * carries `typ`, so it would refuse every conformant token. Rather than
 * loosen the act verifier, this is a second reader, looser about `typ` and
 * about nothing else: `alg` is pinned to ES256, and `none` is refused with
 * everything else.
 *
 * Header parsing (A2cnBearerJwtHeader) and claims parsing
 * (A2cnBearerJwtClaims) are split out: not a design ambition, just what it
 * takes to keep every one of these classes under mago's per-class
 * cyclomatic-complexity budget — a security check like this is mostly
 * fail-closed branches, and each one costs a point.
 *
 * Returns the issuer or null. Null, not an exception: an unauthenticated
 * request is a 401, not an error in our own control flow.
 *
 * ponytail: no (iss, jti) replay store, unlike the reference server. The act
 * append behind this token is idempotent on `message_id`, so a replayed
 * request writes nothing and answers the same 200. Add a store if a token
 * ever authorizes something that is NOT idempotent.
 */
final readonly class A2cnBearerJwt
{
    private const PREFIX = 'Bearer ';

    public function __construct(
        private DidWebResolver $resolver,
    ) {}

    public function issuerOf(string $authorizationHeader, string $audience, \DateTimeImmutable $now): ?string
    {
        if (!str_starts_with($authorizationHeader, self::PREFIX)) {
            return null;
        }

        $segments = explode('.', substr($authorizationHeader, \strlen(self::PREFIX)));
        if (\count($segments) !== 3) {
            return null;
        }

        // The `count($segments) !== 3` guard above already proves $header,
        // $payload and $signature are all set; the analyzer cannot follow
        // that through the list-destructure, so it sees `string|null`.
        [$header, $payload, $signature] = $segments;
        $kid = A2cnBearerJwtHeader::pinnedKidOf(Base64Url::decode($header));
        /** @mago-expect analysis:possibly-null-argument */
        $unverifiedIssuer = A2cnBearerJwtClaims::unverifiedIssuerOf(Base64Url::decode($payload), $audience, $now);
        if ($kid === null || $unverifiedIssuer === null) {
            return null;
        }

        $pem = $this->resolver->publicKeyPemFor($kid);

        // Only past this point — signature verified against the key $kid
        // resolved to — is $unverifiedIssuer safe to hand back as the issuer.
        /** @mago-expect analysis:possibly-null-argument */
        return $pem !== null && self::signatureIsGood($header . '.' . $payload, $signature, $pem)
            ? $unverifiedIssuer
            : null;
    }

    private static function signatureIsGood(string $signingInput, string $signature, string $pem): bool
    {
        try {
            $der = Es256Signature::toDer(Base64Url::decode($signature));
        } catch (MalformedSignature) {
            return false;
        }

        return openssl_verify($signingInput, $der, $pem, \OPENSSL_ALGO_SHA256) === 1;
    }
}
