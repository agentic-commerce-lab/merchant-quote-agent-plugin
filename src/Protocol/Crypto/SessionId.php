<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Crypto;

/**
 * The A2CN session id: RFC 4122 name-based UUIDv5 (SHA-1) over the Shopware
 * quote id, under the namespace from the A2CN spec's Appendix A.
 *
 * Derived rather than generated, for three reasons that all matter: emission
 * stays idempotent across restarts and workers, a counterparty can recompute
 * the id from the quote it already holds, and the id is unguessable to anyone
 * who does not — which is what lets the records endpoints treat it as the
 * capability.
 *
 * Fifteen lines of `sha1()`, so no UUID dependency: Shopware's own Uuid helper
 * only makes random v4 ids.
 */
final class SessionId
{
    public const NAMESPACE = 'f4a2c1e0-8b3d-4f7a-9c2e-1d5b6a8f3e7c';

    private function __construct() {}

    public static function forQuote(string $quoteId): string
    {
        return self::derive($quoteId);
    }

    public static function derive(string $name, string $namespace = self::NAMESPACE): string
    {
        $bytes = hex2bin(str_replace('-', '', $namespace));
        if ($bytes === false) {
            throw new \InvalidArgumentException(\sprintf('Not a UUID namespace: %s', $namespace));
        }

        $digest = sha1($bytes . $name, binary: true);
        // Version 5 in the high nibble of octet 6; RFC 4122 variant in octet 8.
        $digest[6] = \chr((\ord($digest[6]) & 0x0F) | 0x50);
        $digest[8] = \chr((\ord($digest[8]) & 0x3F) | 0x80);

        $hex = bin2hex(substr($digest, 0, 16));

        return implode('-', [
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        ]);
    }
}
