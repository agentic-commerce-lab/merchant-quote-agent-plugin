<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Did;

/**
 * Whether a did:web document authorizes a verification method to sign.
 *
 * Split out of DidWebJwk (a real seam, and what keeps that class under
 * mago's per-class cyclomatic-complexity gate): DidWebJwk picks and
 * normalizes the JWK a method names, a different concern from whether the
 * document actually trusts that method for assertions at all. A method
 * merely listed under `verificationMethod` is not enough — the document must
 * also list it under `assertionMethod` or `authentication` (W3C DID Core
 * §5.3), or a `keyAgreement`-only key (meant for encryption key exchange,
 * never signing) would verify an act same as a real signing key would.
 */
final class DidWebKeyAuthorization
{
    private function __construct() {}

    /** @param array<array-key, mixed> $document */
    public static function permits(array $document, string $verificationMethod): bool
    {
        return (
            self::listsMethod($document['assertionMethod'] ?? null, $verificationMethod)
            || self::listsMethod($document['authentication'] ?? null, $verificationMethod)
        );
    }

    /**
     * A DID document may list either form under `assertionMethod` /
     * `authentication`: the method's id as a bare string, or the
     * verification method embedded inline. Both name the same method, so
     * both count as authorization.
     */
    private static function listsMethod(mixed $list, string $verificationMethod): bool
    {
        if (!\is_array($list)) {
            return false;
        }

        foreach ($list as $entry) {
            if ($entry === $verificationMethod) {
                return true;
            }

            if (\is_array($entry) && ($entry['id'] ?? null) === $verificationMethod) {
                return true;
            }
        }

        return false;
    }
}
