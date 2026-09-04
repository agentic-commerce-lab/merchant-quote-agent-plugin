<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Emitter;

use MerchantQuoteAgentPlugin\Protocol\Crypto\CompactJws;
use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnKeyStore;
use MerchantQuoteAgentPlugin\Protocol\Identity\MissingSigningKey;

/**
 * The proof fields for a signed view: `base64url(SHA-256(JCS(view)))` and an
 * ES256 compact JWS over that hash string (spec 7.4).
 *
 * Signing throws when no key is configured. That is deliberate — see
 * A2cnKeyStore: an act signed with a key nobody can resolve is evidence that
 * looks real and is worthless, so the emitter would rather emit nothing.
 */
final readonly class ActSigner
{
    public function __construct(
        private A2cnKeyStore $keys,
        private ProtocolHash $hash,
    ) {}

    /**
     * @param array<string, mixed> $signedView
     *
     * @return array{protocol_act_hash: string, protocol_act_signature: string}
     *
     * @throws MissingSigningKey
     */
    public function proofFor(array $signedView): array
    {
        $digest = $this->hash->of($signedView);

        return [
            'protocol_act_hash' => $digest,
            'protocol_act_signature' => CompactJws::sign($digest, $this->keys->current()->privateKeyPem),
        ];
    }
}
