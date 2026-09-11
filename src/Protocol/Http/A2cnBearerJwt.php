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
 * `kid` must be controlled by `iss` — the same relationship
 * `ActVerifier::verificationMethodMismatch()` enforces for acts, and for the
 * same reason: without it, anyone holding a did:web key for ANY DID can mint
 * a token that authenticates as ANY OTHER DID. Header `kid:
 * did:web:mallory.example#key-1`, claims `iss: did:web:buyer.example`,
 * signed with Mallory's own key — `publicKeyPemFor($kid)` resolves exactly
 * the key the signature was made with, so the signature verifies, and
 * without this check `issuerOf()` would hand back the buyer's DID anyway.
 * That would nullify `InboundActEnvelope`'s §14.1 `sender_did_mismatch`
 * binding, which trusts this method's return value to BE the authenticated
 * sender.
 *
 * Checked before `publicKeyPemFor()`, not after, for the reason
 * `ActVerifier`'s own step 0 gives: it is a free local comparison, and
 * running it before the network hop means a token that could never pass
 * never causes one.
 *
 * ponytail: no (iss, jti) replay store, unlike the reference server. The act
 * append behind this token is idempotent on `message_id`, so a replayed
 * request writes nothing and answers the same 200. Add a store if a token
 * ever authorizes something that is NOT idempotent.
 *
 * Not `final`: the tests substitute it.
 */
class A2cnBearerJwt
{
    private const PREFIX = 'Bearer ';

    public function __construct(
        private readonly DidWebResolver $resolver,
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

        if ($kid !== $unverifiedIssuer && !str_starts_with($kid, $unverifiedIssuer . '#')) {
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
