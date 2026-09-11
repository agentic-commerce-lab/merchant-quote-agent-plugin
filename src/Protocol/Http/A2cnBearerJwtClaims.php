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
     * being usable by whoever picks it out of a log. An empty `iss` is
     * refused rather than treated as "no claim to make".
     *
     * `aud` is read as an array either way: `(array) $aud` turns a bare
     * StringOrURI into a one-element array and leaves an array as-is —
     * RFC 7519 §4.1.3 allows either shape, the counterparty's own server
     * issues both, and casting instead of branching on `is_string()`/
     * `is_array()` is what keeps this one added rule from pushing the class
     * over its complexity budget. Either shape still has to contain the
     * audience, exactly, not merely resemble it.
     *
     * `exp` accepts a PHP int or float: RFC 7519 §4.1.4 defines NumericDate,
     * which MAY be fractional, and the counterparty's own server issues both
     * shapes too. A numeric STRING is refused regardless — JSON has a number
     * type, and a client sending `"exp": "4000000000"` is not conformant, so
     * accepting it would be leniency with no interop payoff.
     */
    public static function unverifiedIssuerOf(string $decoded, string $audience, \DateTimeImmutable $now): ?string
    {
        $claims = json_decode($decoded, associative: true);
        if (!\is_array($claims)) {
            return null;
        }

        $issuer = $claims['iss'] ?? null;
        $audiences = (array) ($claims['aud'] ?? null);
        if (!\is_string($issuer) || $issuer === '' || !\in_array($audience, $audiences, strict: true)) {
            return null;
        }

        $expires = $claims['exp'] ?? null;

        return (\is_int($expires) || \is_float($expires)) && $expires > $now->getTimestamp() ? $issuer : null;
    }
}
