<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Http;

/**
 * The claim set of the A2CN transport token.
 *
 * Split out of A2cnBearerJwt for the same reason as A2cnBearerJwtHeader: it
 * keeps this class's share of the cyclomatic-complexity budget on its own,
 * separate from header parsing and signature verification.
 *
 * @internal `unverifiedIssuerOf()` checks shape only — `aud`, `exp`, a
 * non-empty `iss` — never a signature. Its return value is NOT authenticated
 * and must never be treated as "who sent this". Only A2cnBearerJwt::issuerOf()
 * may hand out an issuer, and only after the signature also checks out.
 */
final class A2cnBearerJwtClaims
{
    private function __construct() {}

    /**
     * The `iss` of a token whose claims are shaped like a live one addressed
     * to us — unverified, see the class docblock.
     *
     * An expiry is REQUIRED, not optional: a token with none never stops
     * being usable by whoever picks it out of a log. `aud` must match
     * exactly, and an empty `iss` is refused rather than treated as "no
     * claim to make".
     */
    public static function unverifiedIssuerOf(string $decoded, string $audience, \DateTimeImmutable $now): ?string
    {
        $claims = json_decode($decoded, associative: true);
        if (!\is_array($claims)) {
            return null;
        }

        $issuer = $claims['iss'] ?? null;
        $expires = $claims['exp'] ?? null;
        if (!\is_string($issuer) || $issuer === '' || ($claims['aud'] ?? null) !== $audience) {
            return null;
        }

        return \is_int($expires) && $expires > $now->getTimestamp() ? $issuer : null;
    }
}
