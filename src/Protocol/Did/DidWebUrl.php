<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Did;

/**
 * `did:web` → the URL its document is served from (W3C did:web method).
 *
 * Host-only DIDs resolve under `/.well-known/`; a DID with path segments
 * resolves at those segments and drops `/.well-known/` entirely, which is the
 * part of the method most implementations get wrong.
 */
final class DidWebUrl
{
    private const PREFIX = 'did:web:';

    private function __construct() {}

    public static function forDid(string $did): ?string
    {
        if (!str_starts_with($did, self::PREFIX)) {
            return null;
        }

        $identifier = substr($did, \strlen(self::PREFIX));
        if ($identifier === '') {
            return null;
        }

        $segments = explode(':', $identifier);
        $authority = rawurldecode(array_shift($segments));
        if ($authority === '') {
            return null;
        }

        return (
            $segments === []
                ? \sprintf('https://%s/.well-known/did.json', $authority)
                : \sprintf('https://%s/%s/did.json', $authority, implode('/', array_map(rawurldecode(...), $segments)))
        );
    }

    /** The DID part of a verification method (`did:web:host#key-1`). */
    public static function didOf(string $verificationMethod): ?string
    {
        $position = strpos($verificationMethod, '#');

        return $position === false || $position === 0 ? null : substr($verificationMethod, 0, $position);
    }

    /** The fragment, used as the `kid` a DID document usually omits. */
    public static function fragmentOf(string $verificationMethod): string
    {
        $position = strpos($verificationMethod, '#');

        return $position === false ? $verificationMethod : substr($verificationMethod, $position + 1);
    }
}
