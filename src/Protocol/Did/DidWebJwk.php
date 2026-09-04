<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Did;

/**
 * Pure reads over a decoded did:web document: pick the JWK a verification
 * method names, and normalize it for the SDK.
 *
 * Split out of DidWebResolver: this is document parsing, a different concern
 * from the HTTP fetch (DidWebDocumentFetcher) and from turning a resolved key
 * into a PEM (DidWebResolver itself, which needs the SDK's key manager).
 */
final class DidWebJwk
{
    private function __construct() {}

    /**
     * @param array<array-key, mixed> $document
     *
     * @return array<string, mixed>|null
     */
    public static function pick(array $document, string $verificationMethod): ?array
    {
        $methods = $document['verificationMethod'] ?? null;
        if (!\is_array($methods)) {
            return null;
        }

        foreach ($methods as $entry) {
            if (!\is_array($entry) || ($entry['id'] ?? null) !== $verificationMethod) {
                continue;
            }

            $jwk = $entry['publicKeyJwk'] ?? null;

            /** @var array<string, mixed>|null $jwk */
            return \is_array($jwk) ? $jwk : null;
        }

        return null;
    }

    /**
     * Fills the fields `PublicSigningKey::fromJwk()` requires (spec 7.4) that a
     * real DID document omits: `kid` from the verification method's fragment,
     * `alg`/`use`/`kty` fixed, since this resolver only ever reads ES256 EC
     * signing keys.
     *
     * @param array<string, mixed> $jwk
     *
     * @return array<string, string>
     */
    public static function normalize(array $jwk, string $verificationMethod): array
    {
        $scalars = array_filter($jwk, static fn(mixed $value): bool => \is_scalar($value));
        $normalized = array_map(strval(...), $scalars);
        $normalized['kid'] = DidWebUrl::fragmentOf($verificationMethod);
        $normalized['alg'] = 'ES256';
        $normalized['use'] = 'sig';
        $normalized['kty'] = 'EC';

        return $normalized;
    }
}
