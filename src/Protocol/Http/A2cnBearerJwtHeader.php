<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Http;

/**
 * The protected header of the A2CN transport token.
 *
 * Split out of A2cnBearerJwt, alongside A2cnBearerJwtClaims: a single class
 * doing header parsing, claims parsing, and signature verification trips
 * mago's per-class cyclomatic-complexity budget (10) even though no one
 * method is complex — every fail-closed guard on a security boundary adds
 * one more branch. Three small classes under the budget beat one over it.
 */
final class A2cnBearerJwtHeader
{
    private const ALG = 'ES256';

    private function __construct() {}

    /**
     * The `kid` of an ES256 header, or null for anything else — `none`
     * included. `alg` is checked first and always: nothing downstream may
     * run against a header this did not pin to ES256.
     */
    public static function kidOf(string $decoded): ?string
    {
        $header = json_decode($decoded, associative: true);
        if (!\is_array($header) || ($header['alg'] ?? null) !== self::ALG) {
            return null;
        }

        $kid = $header['kid'] ?? null;

        return \is_string($kid) && $kid !== '' ? $kid : null;
    }
}
