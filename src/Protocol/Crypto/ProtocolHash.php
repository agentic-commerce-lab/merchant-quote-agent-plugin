<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Crypto;

use Ucp\Sdk\Service\DeterministicJsonInterface;

/**
 * `base64url(SHA-256(JCS(value)))` — the digest A2CN signs over.
 *
 * RFC 8785 canonicalization comes from the UCP SDK, which already needs it for
 * request signing; a second implementation in this plugin would be a second
 * thing to get wrong. The SDK's implementation is `@internal` behind the public
 * `DeterministicJsonInterface` alias, so we depend on the interface and pin the
 * resulting bytes in ProtocolHashTest: an SDK change that moves them fails
 * there rather than silently diverging a counterparty's hash.
 *
 * Whether an optional field is absent or explicitly null is the caller's
 * choice, and both are free here: the canonicalizer receives PHP arrays, so an
 * unset key simply is not in the array and a `null` one canonicalizes as
 * `null`. Which of the two is correct depends on the object — `terms` and our
 * own records omit, the protocol act object nulls (see SignedView).
 */
final readonly class ProtocolHash
{
    public function __construct(
        private DeterministicJsonInterface $json,
    ) {}

    /** @param array<array-key, mixed> $value */
    public function of(array $value): string
    {
        return Base64Url::encode(hash('sha256', $this->canonical($value), binary: true));
    }

    /**
     * The canonical string itself. Used for equality comparisons where the
     * digest would be wasted work — "the signed bytes would change" is exactly
     * "the canonical form differs".
     *
     * @param array<array-key, mixed> $value
     */
    public function canonical(array $value): string
    {
        // The SDK interface documents `array<string, mixed>`, but its own
        // canonicalizer handles both JSON objects and JSON arrays (see
        // ProtocolHashTest::testItHashesAListForTheOfferChain, which hashes a
        // plain int-keyed list) — the interface's phpdoc is narrower than
        // what it actually accepts.
        /** @mago-expect analysis:less-specific-argument */
        return $this->json->canonicalize($value);
    }
}
